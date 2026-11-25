<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Events;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\ContactFlowStartCollision;
use App\Jobs\Flow\StartFlowFromEventJob;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Stringable;
use Tests\Feature\Domains\Flow\StartFlow\RacingStartKeyRepository;
use Tests\Feature\FeatureTestCase;

/**
 * Covers the event-triggered flow start: the resolved trigger's flow is started for the emitting contact, with the
 * event payload exposed under `flow.event.*`, once per trigger and event, and never over a busy contact, an operator or
 * a contact without a channel on the trigger's assistant — the rules of a `start_flow` start.
 */
final class StartFlowFromEventJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    private Contact $contact;

    private Channel $channel;

    private string $flowId;

    /** @var object{records: list<array{message: string, context: array<mixed>}>} */
    private object $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $this->app->instance(LoggerInterface::class, $this->logger);

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-1']);
        $this->channel   = $this->channelOf($this->assistant);
        ChannelContact::query()->create([
            'contact_id'          => $this->contact->getKey(),
            'channel_id'          => $this->channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $this->flowId = $this->endFlow();
    }

    /**
     * @return array<string, array{ConversationOwner}>
     */
    public static function operatorOwners(): array
    {
        return [
            'an operator handles it' => [ConversationOwner::Staff],
            'it waits for one'       => [ConversationOwner::Queued],
        ];
    }

    public function test_starts_the_subscribed_flow_with_event_payload_in_state(): void
    {
        $this->runJob($this->job(payload: ['amount' => 42, 'currency' => 'USD']));

        /** @var FlowSession $session */
        $session = FlowSession::query()
            ->where('contact_id', $this->contact->getKey())
            ->latest('created_at')
            ->first();

        $this->assertNotNull($session, 'The subscribed flow should have started a session.');
        $this->assertSame((string) $this->assistant->getKey(), (string) $session->assistant_id);
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(EndStatus::Success->value, $session->end_status);
        $this->assertSame(['amount' => 42, 'currency' => 'USD'], $session->state['flow']['event'] ?? null);
        $this->assertStringStartsWith('event:', (string) $session->start_key);
        $this->assertCount(1, $this->logged('flow.event.started'));
        $this->assertSame((string) $session->getKey(), $this->logged('flow.event.started')[0]['session_id']);
        $this->assertSame([], $this->logged('flow.event.start_duplicate'));
    }

    public function test_does_nothing_when_flow_is_not_active(): void
    {
        $this->runJob($this->job(flowId: (string) Str::uuid()));

        $this->assertSame(
            0,
            FlowSession::query()->where('contact_id', $this->contact->getKey())->count(),
            'No session should be created when the flow has no active definition.',
        );
    }

    public function test_a_retried_job_starts_the_flow_once_per_trigger_and_event(): void
    {
        $triggerId = (string) Str::uuid();

        $this->runJob($this->job(triggerId: $triggerId, eventId: 'event-1'));
        $this->assertSame([], $this->logged('flow.event.start_duplicate'));

        $this->runJob($this->job(triggerId: $triggerId, eventId: 'event-1'));

        $this->assertCount(1, $this->logged('flow.event.start_duplicate'));
        $this->assertSame(1, $this->startedSessions()->count());
        $this->assertSame(
            'event:' . hash('sha256', $triggerId . ':event:event-1'),
            $this->startedSessions()->sole()->start_key,
        );

        $this->runJob($this->job(triggerId: $triggerId, eventId: 'event-2'));
        $this->runJob($this->job(triggerId: (string) Str::uuid(), eventId: 'event-1'));

        $this->assertSame(3, $this->startedSessions()->count(), 'Another event, or another trigger of the event, starts again.');
    }

    #[DataProvider('operatorOwners')]
    public function test_a_thread_with_an_operator_starts_nothing(ConversationOwner $owner): void
    {
        $live = $this->liveConversation(Carbon::now()->subHours(2));
        Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $this->assistant->getKey(),
            'contact_id'   => $this->contact->getKey(),
            'channel_id'   => $this->channel->getKey(),
            'platform'     => 'telegram',
            'owner_type'   => $owner,
        ]);

        $this->runJob($this->job());

        $this->assertSame(0, $this->startedSessions()->count());
        $this->assertSame(FlowSessionStatus::WaitingInput, $live->refresh()->status, 'The operator\'s conversation is untouched.');
        $this->assertBusyBecause(ContactFlowStartCollision::REASON_OPERATOR);
    }

    public function test_a_conversation_that_moved_within_the_window_starts_nothing(): void
    {
        $live = $this->liveConversation(Carbon::now()->subMinutes(5));

        $this->runJob($this->job());

        $this->assertSame(0, $this->startedSessions()->count());
        $this->assertSame(FlowSessionStatus::WaitingInput, $live->refresh()->status);
        $this->assertBusyBecause(ContactFlowStartCollision::REASON_CONVERSATION_MOVING);
    }

    public function test_a_stale_conversation_is_ended_and_replaced(): void
    {
        $stale = $this->liveConversation(Carbon::now()->subMinutes(31));

        $this->runJob($this->job());

        $this->assertSame(FlowSessionStatus::TerminatedByUser, $stale->refresh()->status);
        $this->assertSame(1, $this->startedSessions()->count());
        $this->assertSame(
            0,
            FlowSession::query()->where('contact_id', $this->contact->getKey())->whereIn('status', FlowSessionStatus::inFlightValues())->count(),
            'The contact is left with no second live conversation with the bot.',
        );
        $this->assertSame(1, $this->logged('flow.event.started')[0]['ended_stale']);
    }

    public function test_a_conversation_asleep_in_a_delay_is_never_ended_by_an_event(): void
    {
        $sleeping = $this->liveConversation(Carbon::now()->subDays(2), FlowSessionStatus::Paused);

        $this->runJob($this->job());

        $this->assertSame(0, $this->startedSessions()->count());
        $this->assertSame(FlowSessionStatus::Paused, $sleeping->refresh()->status);
        $this->assertBusyBecause(ContactFlowStartCollision::REASON_CONVERSATION_SCHEDULED);
    }

    public function test_the_conversation_that_emitted_the_event_is_never_ended_by_it(): void
    {
        $emitter = $this->liveConversation(Carbon::now()->subHours(2));

        $this->runJob($this->job(sourceSessionId: (string) $emitter->getKey()));

        $this->assertSame(0, $this->startedSessions()->count());
        $this->assertSame(FlowSessionStatus::WaitingInput, $emitter->refresh()->status);
        $this->assertBusyBecause(ContactFlowStartCollision::REASON_OWN_CONVERSATION);
    }

    public function test_an_emitting_conversation_that_has_ended_does_not_hold_the_start(): void
    {
        $emitter = $this->liveConversation(Carbon::now(), FlowSessionStatus::Ended);

        $this->runJob($this->job(sourceSessionId: (string) $emitter->getKey()));

        $this->assertSame(1, $this->startedSessions()->count());
    }

    public function test_a_conversation_with_another_bot_does_not_hold_the_start(): void
    {
        $other = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $live  = $this->liveConversation(Carbon::now(), assistant: $other);

        $this->runJob($this->job());

        $this->assertSame(1, $this->startedSessions()->count());
        $this->assertSame(FlowSessionStatus::WaitingInput, $live->refresh()->status);
    }

    public function test_a_contact_without_a_channel_on_the_trigger_s_assistant_starts_nothing(): void
    {
        Channel::withoutEvents(fn () => $this->channel->update(['is_active' => false]));

        $this->runJob($this->job());

        $this->assertSame(0, FlowSession::query()->where('contact_id', $this->contact->getKey())->count());
        $this->assertCount(1, $this->logged('flow.event.start_skipped_no_channel'));
        $this->assertSame([], $this->logged('flow.event.started'));
    }

    public function test_a_duplicate_that_won_the_race_past_the_lock_is_logged_as_a_duplicate(): void
    {
        $triggerId = (string) Str::uuid();
        $startKey  = 'event:' . hash('sha256', $triggerId . ':event:event-1');

        // The duplicate's row lands between this job's look-ups and its insert.
        $real = $this->app->make(FlowSessionRepositoryInterface::class);
        $this->app->instance(FlowSessionRepositoryInterface::class, new RacingStartKeyRepository($real, function () use ($startKey): void {
            $definition = FlowDefinition::query()->where('flow_id', $this->flowId)->sole();
            FlowSession::query()->create([
                'tenant_id'          => self::TENANT_ID,
                'assistant_id'       => $this->assistant->getKey(),
                'contact_id'         => $this->contact->getKey(),
                'flow_definition_id' => $definition->getKey(),
                'flow_version'       => 1,
                'state'              => [],
                'status'             => FlowSessionStatus::Completed,
                'start_key'          => $startKey,
                'version'            => 1,
            ]);
        }));

        $this->runJob($this->job(triggerId: $triggerId, eventId: 'event-1'));

        $this->assertSame(1, FlowSession::query()->where('start_key', $startKey)->count());
        $this->assertSame([], $this->logged('flow.event.started'));
        $this->assertCount(1, $this->logged('flow.event.start_duplicate'));
    }

    public function test_a_job_queued_before_the_ids_existed_starts_once_keyed_by_its_own_id(): void
    {
        $old = $this->queuedBeforeTheIds('job-uuid-1');

        $this->runJob($old);
        $this->runJob($old);

        $this->assertSame(1, $this->startedSessions()->count());
        $this->assertSame('event:' . hash('sha256', ':job:job-uuid-1'), $this->startedSessions()->sole()->start_key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function job(
        ?string $flowId = null,
        array $payload = [],
        ?string $triggerId = null,
        ?string $eventId = null,
        ?string $sourceSessionId = null,
    ): StartFlowFromEventJob {
        return new StartFlowFromEventJob(
            tenantId: self::TENANT_ID,
            flowId: $flowId ?? $this->flowId,
            assistantId: (string) $this->assistant->getKey(),
            contactId: (string) $this->contact->getKey(),
            payload: $payload,
            eventName: 'order.created',
            triggerId: $triggerId,
            eventId: $eventId ?? (string) Str::uuid(),
            sourceSessionId: $sourceSessionId,
        );
    }

    /**
     * A job serialized before the trigger, event and source session ids existed: it unserializes without them.
     */
    private function queuedBeforeTheIds(string $jobUuid): StartFlowFromEventJob
    {
        $reflection = new ReflectionClass(StartFlowFromEventJob::class);
        $job        = $reflection->newInstanceWithoutConstructor();

        foreach ([
            'tenantId'    => self::TENANT_ID,
            'flowId'      => $this->flowId,
            'assistantId' => (string) $this->assistant->getKey(),
            'contactId'   => (string) $this->contact->getKey(),
            'payload'     => [],
            'eventName'   => 'order.created',
        ] as $name => $value) {
            $reflection->getProperty($name)->setValue($job, $value);
        }

        $queued = Mockery::mock(QueueJob::class);
        $queued->shouldReceive('uuid')->andReturn($jobUuid);
        $job->setJob($queued);

        return $job;
    }

    /**
     * The context of every record logged under the message.
     *
     * @return list<array<mixed>>
     */
    private function logged(string $message): array
    {
        return array_values(array_map(
            static fn (array $record): array => $record['context'],
            array_filter($this->logger->records, static fn (array $record): bool => $message === $record['message']),
        ));
    }

    private function assertBusyBecause(string $reason): void
    {
        $busy = $this->logged('flow.event.start_skipped_busy');

        $this->assertCount(1, $busy);
        $this->assertSame($reason, $busy[0]['reason']);
        $this->assertSame([], $this->logged('flow.event.started'));
    }

    private function runJob(StartFlowFromEventJob $job): void
    {
        $this->app->call([$job, 'handle']);
    }

    /**
     * @return Builder<FlowSession>
     */
    private function startedSessions(): Builder
    {
        return FlowSession::query()->where('contact_id', $this->contact->getKey())->whereNotNull('start_key');
    }

    private function liveConversation(
        Carbon $updatedAt,
        FlowSessionStatus $status = FlowSessionStatus::WaitingInput,
        ?Assistant $assistant = null,
    ): FlowSession {
        $definition = FlowDefinition::query()->where('flow_id', $this->flowId)->sole();

        $session = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => ($assistant ?? $this->assistant)->getKey(),
            'contact_id'         => $this->contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'ask',
            'state'              => [],
            'status'             => $status,
            'version'            => 1,
        ]);

        FlowSession::query()->whereKey($session->getKey())->update(['updated_at' => $updatedAt]);

        return $session->refresh();
    }

    private function channelOf(Assistant $assistant): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
    }

    private function endFlow(): string
    {
        $flowId = (string) Str::uuid();

        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Event Flow',
            'nodes'     => [
                ['id' => 'n-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        return $flowId;
    }
}
