<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\StartFlow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Flow\Contracts\ContactFlowStartDispatcherInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\DTOs\ContactFlowStart;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\StartFlowNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\EventChainContext;
use App\Jobs\Flow\StartFlowForContactJob;
use Closure;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The `start_flow` node and its job: a flow started as a contact's conversation with the target flow's bot, once per
 * step, never over a moving conversation or an operator, and never for a contact without a channel.
 */
final class StartFlowForContactTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private RecordingContactFlowStartDispatcher $dispatcher;

    private Assistant $assistant;

    private Contact $customer;

    private Channel $channel;

    private string $targetFlowId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dispatcher = new RecordingContactFlowStartDispatcher();
        $this->app->instance(ContactFlowStartDispatcherInterface::class, $this->dispatcher);

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);
        $this->customer  = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-1']);
        $this->channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
        ChannelContact::query()->create([
            'contact_id'          => $this->customer->getKey(),
            'channel_id'          => $this->channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $this->targetFlowId = $this->targetFlow();
    }

    public function test_a_contactless_run_queues_the_start_and_goes_on_by_started(): void
    {
        $source = $this->definition([
            ['id' => 'start', 'type' => 'start_flow', 'version' => 1, 'config' => [
                'flow_id'       => $this->targetFlowId,
                'addressee'     => ['kind' => 'contact', 'contact' => '{{flow.customer_id}}'],
                'input_mapping' => ['order_id' => '{{flow.order_id}}'],
            ]],
            ['id' => 'done', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [['id' => 'e1', 'from' => 'start', 'to' => 'done', 'handle' => 'started']]);

        $run = $this->app->make(FlowEngineInterface::class)->start(
            $source,
            null,
            ['flow' => ['customer_id' => (string) $this->customer->getKey(), 'order_id' => 42]],
            FlowRunOrigin::automation((string) Str::uuid()),
        );

        $this->assertSame(FlowSessionStatus::Ended, $run->status);
        $this->assertCount(1, $this->dispatcher->starts);

        $start = $this->dispatcher->starts[0];
        $this->assertSame((string) $this->customer->getKey(), $start->contactId);
        $this->assertSame(['order_id' => 42], $start->input);
        $this->assertSame(1, $start->chainDepth);
        $this->assertStringEndsWith(':start', $start->startKey);

        $this->runJob($start);

        $conversation = FlowSession::query()->where('start_key', $start->startKey)->sole();
        $this->assertSame(FlowRunKind::Conversation, $conversation->run_kind);
        $this->assertSame((string) $this->customer->getKey(), (string) $conversation->contact_id);
        $this->assertSame((string) $this->assistant->getKey(), (string) $conversation->assistant_id);
        $this->assertSame(42, $conversation->state['flow']['order_id'] ?? null);
        $this->assertSame(1, (int) $conversation->event_chain_depth);
    }

    public function test_a_repeated_step_whose_start_ran_leaves_by_started_and_queues_nothing(): void
    {
        $context = $this->context();
        $first   = $this->execute($this->config(), $context);
        $this->assertSame('started', $first->sourceHandle);

        $this->runJob($this->dispatcher->starts[0]);
        $this->dispatcher->starts = [];

        // The started conversation is live and moving: a repeat must still say started, not busy.
        $repeat = $this->execute($this->config(), $context);

        $this->assertSame('started', $repeat->sourceHandle);
        $this->assertSame([], $this->dispatcher->starts);
        $this->assertSame(1, FlowSession::query()->where('contact_id', $this->customer->getKey())->count());
    }

    public function test_a_retried_or_duplicate_job_starts_the_conversation_once(): void
    {
        $this->execute($this->config(), $this->context());
        $start = $this->dispatcher->starts[0];

        $this->runJob($start);
        $this->runJob($start);

        $this->assertSame(1, FlowSession::query()->where('start_key', $start->startKey)->count());
    }

    public function test_the_start_key_is_unique_on_the_session_row(): void
    {
        $definition = FlowDefinition::query()->where('flow_id', $this->targetFlowId)->sole();
        $row        = [
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => $this->customer->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'state'              => [],
            'status'             => FlowSessionStatus::Completed,
            'start_key'          => 'k:n',
            'version'            => 1,
        ];
        FlowSession::query()->create($row);

        $this->expectException(UniqueConstraintViolationException::class);
        FlowSession::query()->create($row);
    }

    public function test_each_step_of_a_loop_gets_its_own_start(): void
    {
        $this->execute($this->config(), $this->context('step-1'));
        $this->execute($this->config(), $this->context('step-2'));

        $this->assertCount(2, $this->dispatcher->starts);
        $this->assertNotSame($this->dispatcher->starts[0]->startKey, $this->dispatcher->starts[1]->startKey);
    }

    public function test_an_unresolved_addressee_is_an_error_and_never_the_run_s_contact(): void
    {
        $result = $this->execute(
            [...$this->config(), 'addressee' => ['kind' => 'contact', 'contact' => '{{flow.missing}}']],
            $this->context(contactId: (string) $this->customer->getKey()),
        );

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('addressee_unresolved', $result->metadata['error_type']);
        $this->assertSame([], $this->dispatcher->starts);
    }

    public function test_an_unknown_contact_is_an_error(): void
    {
        $result = $this->execute([...$this->config(), 'addressee' => ['kind' => 'contact', 'contact' => (string) Str::uuid()]]);

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('contact_not_found', $result->metadata['error_type']);
    }

    public function test_a_target_without_an_active_version_is_an_error(): void
    {
        FlowDefinition::query()->where('flow_id', $this->targetFlowId)->update(['is_active' => false]);

        $result = $this->execute($this->config());

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('target_inactive', $result->metadata['error_type']);
    }

    public function test_a_contact_without_a_channel_on_an_active_channel_of_the_target_s_bot_leaves_by_no_channel(): void
    {
        Channel::withoutEvents(fn () => $this->channel->update(['is_active' => false]));

        $result = $this->execute($this->config());

        $this->assertSame('no_channel', $result->sourceHandle);
        $this->assertSame([], $this->dispatcher->starts);
    }

    public function test_a_channel_gone_by_the_time_of_the_job_starts_nothing_and_does_not_fail(): void
    {
        $this->execute($this->config(), $this->context());
        Channel::withoutEvents(fn () => $this->channel->update(['is_active' => false]));

        $this->runJob($this->dispatcher->starts[0]);

        $this->assertSame(0, FlowSession::query()->where('contact_id', $this->customer->getKey())->count());
    }

    public function test_a_run_at_the_deepest_chain_level_cannot_start_another_flow(): void
    {
        $this->app->make(EventChainContext::class)->enter(5);

        $result = $this->execute($this->config());

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('chain_depth_exceeded', $result->metadata['error_type']);
        $this->assertSame([], $this->dispatcher->starts);
    }

    public function test_the_started_conversation_is_one_level_deeper_than_the_run(): void
    {
        $this->app->make(EventChainContext::class)->enter(3);

        $this->execute($this->config());

        $this->assertSame(4, $this->dispatcher->starts[0]->chainDepth);
    }

    public function test_a_conversation_that_moved_within_the_window_makes_the_contact_busy(): void
    {
        $live = $this->liveConversation(Carbon::now()->subMinutes(5));

        $result = $this->execute($this->config());

        $this->assertSame('busy', $result->sourceHandle);
        $this->assertSame('conversation_moving', $result->metadata['reason']);
        $this->assertSame([], $this->dispatcher->starts);
        $this->assertSame(FlowSessionStatus::WaitingInput, $live->refresh()->status);
    }

    public function test_a_thread_with_an_operator_makes_the_contact_busy(): void
    {
        Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $this->assistant->getKey(),
            'contact_id'   => $this->customer->getKey(),
            'channel_id'   => $this->channel->getKey(),
            'platform'     => 'telegram',
            'owner_type'   => ConversationOwner::Queued,
        ]);

        $result = $this->execute($this->config());

        $this->assertSame('busy', $result->sourceHandle);
        $this->assertSame('operator', $result->metadata['reason']);
    }

    public function test_a_stale_conversation_is_ended_and_replaced(): void
    {
        $stale = $this->liveConversation(Carbon::now()->subHours(2));

        $result = $this->execute($this->config(), $this->context());
        $this->assertSame('started', $result->sourceHandle);

        $this->runJob($this->dispatcher->starts[0]);

        $this->assertSame(FlowSessionStatus::TerminatedByUser, $stale->refresh()->status);
        $this->assertSame(1, FlowSession::query()->where('start_key', $this->dispatcher->starts[0]->startKey)->count());
    }

    public function test_the_job_decides_under_the_lock_when_the_contact_became_busy_after_the_node(): void
    {
        $this->execute($this->config(), $this->context());
        $live = $this->liveConversation(Carbon::now());

        $this->runJob($this->dispatcher->starts[0]);

        $this->assertSame(FlowSessionStatus::WaitingInput, $live->refresh()->status);
        $this->assertSame(0, FlowSession::query()->whereNotNull('start_key')->count());
    }

    public function test_a_conversation_never_ends_itself_even_with_no_busy_window(): void
    {
        $own = $this->liveConversation(Carbon::now()->subHours(2));

        $result = $this->execute(
            [...$this->config(), 'busy_window_minutes' => 0],
            $this->context(sessionId: (string) $own->getKey(), contactId: (string) $this->customer->getKey()),
        );

        $this->assertSame('busy', $result->sourceHandle);
        $this->assertSame('own_conversation', $result->metadata['reason']);
        $this->assertSame(FlowSessionStatus::WaitingInput, $own->refresh()->status);
    }

    public function test_a_conversation_asleep_in_a_delay_is_never_ended_by_a_start(): void
    {
        $sleeping = $this->liveConversation(Carbon::now()->subDays(2), FlowSessionStatus::Paused);

        $result = $this->execute($this->config());
        $this->assertSame('busy', $result->sourceHandle);
        $this->assertSame('conversation_scheduled', $result->metadata['reason']);

        // Under the lock the job decides the same, even when the node found the contact free.
        $this->runJob(new ContactFlowStart(self::TENANT_ID, $this->targetFlowId, (string) $this->customer->getKey(), [], 'k:start', 1, 30, null));

        $this->assertSame(FlowSessionStatus::Paused, $sleeping->refresh()->status);
        $this->assertSame(0, FlowSession::query()->whereNotNull('start_key')->count());
    }

    public function test_a_duplicate_that_won_the_race_past_the_lock_is_a_quiet_exit(): void
    {
        $this->execute($this->config(), $this->context());
        $start = $this->dispatcher->starts[0];

        // The duplicate's row lands between this job's look-ups and its insert.
        $real = $this->app->make(FlowSessionRepositoryInterface::class);
        $this->app->instance(FlowSessionRepositoryInterface::class, new RacingStartKeyRepository($real, function () use ($start): void {
            $definition = FlowDefinition::query()->where('flow_id', $this->targetFlowId)->sole();
            FlowSession::query()->create([
                'tenant_id'          => self::TENANT_ID,
                'assistant_id'       => $this->assistant->getKey(),
                'contact_id'         => $this->customer->getKey(),
                'flow_definition_id' => $definition->getKey(),
                'flow_version'       => 1,
                'state'              => [],
                'status'             => FlowSessionStatus::Completed,
                'start_key'          => $start->startKey,
                'version'            => 1,
            ]);
        }));

        $this->runJob($start);

        $this->assertSame(1, FlowSession::query()->where('start_key', $start->startKey)->count());
    }

    public function test_the_started_conversation_sends_its_first_message_on_the_contact_s_channel(): void
    {
        $flowId = (string) Str::uuid();
        FlowDraft::factory()->create(['tenant_id' => self::TENANT_ID, 'flow_id' => $flowId, 'assistant_id' => $this->assistant->getKey()]);
        $this->definition([
            ['id' => 'hello', 'type' => 'send_message', 'version' => 1, 'config' => ['content_type' => 'text', 'text' => 'Order {{flow.order_id}} is ready']],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [['id' => 'e1', 'from' => 'hello', 'to' => 'end', 'handle' => 'default']], $flowId);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);

        $this->runJob(new ContactFlowStart(self::TENANT_ID, $flowId, (string) $this->customer->getKey(), ['order_id' => '42'], 'k:hello', 1, 30, null));

        Http::assertSent(static fn (Request $request): bool => 'chat-1' === (string) ($request['chat_id'] ?? null)
            && 'Order 42 is ready' === ($request['text'] ?? null));
    }

    public function test_a_contact_of_another_tenant_is_not_found(): void
    {
        $stranger = Contact::factory()->forTenant((string) Str::uuid())->create();

        $result = $this->execute([...$this->config(), 'addressee' => ['kind' => 'contact', 'contact' => (string) $stranger->getKey()]]);

        $this->assertSame('error', $result->sourceHandle);
        $this->assertSame('contact_not_found', $result->metadata['error_type']);
    }

    public function test_a_config_without_a_flow_or_with_a_bad_window_is_refused_at_run_time(): void
    {
        try {
            $this->execute([...$this->config(), 'flow_id' => '']);
            $this->fail('A missing flow_id must be refused.');
        } catch (InvalidNodeConfigException) {
        }

        $this->expectException(InvalidNodeConfigException::class);
        $this->execute([...$this->config(), 'busy_window_minutes' => -1]);
    }

    public function test_publishing_checks_the_addressee_the_window_and_the_target(): void
    {
        $handler = $this->app->make(StartFlowNodeHandler::class);

        $codes = static fn (array $issues): array => array_map(static fn ($issue): string => $issue->code, $issues);

        $this->assertSame(['addressee_required'], $codes($handler->validate(['config' => ['flow_id' => 'x']])));
        $this->assertSame(['addressee_kind_unknown'], $codes($handler->validate(['config' => ['addressee' => ['kind' => 'tag', 'contact' => 'vip']]])));
        $this->assertSame(['start_flow_invalid_busy_window'], $codes($handler->validate(['config' => [
            'addressee'           => ['kind' => 'contact', 'contact' => '{{flow.id}}'],
            'busy_window_minutes' => 5000,
        ]])));
        $this->assertSame([], $codes($handler->validate(['config' => $this->config()])));
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return [
            'flow_id'   => $this->targetFlowId,
            'addressee' => ['kind' => 'contact', 'contact' => '{{flow.customer_id}}'],
        ];
    }

    private function context(string $key = 'exec-1', ?string $sessionId = null, ?string $contactId = null): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: self::TENANT_ID,
            contactId: $contactId,
            sessionId: $sessionId ?? (string) Str::uuid(),
            nodeId: 'start',
            idempotencyKey: $key,
            platform: null,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function execute(array $config, ?NodeExecutionContext $context = null): NodeExecutionResult
    {
        return $this->app->make(StartFlowNodeHandler::class)->execute(
            ['config' => $config],
            ['flow' => ['customer_id' => (string) $this->customer->getKey()]],
            $context ?? $this->context(),
        );
    }

    private function runJob(ContactFlowStart $start): void
    {
        $this->app->forgetScopedInstances();

        $job = new StartFlowForContactJob(
            $start->tenantId,
            $start->flowId,
            $start->contactId,
            $start->input,
            $start->startKey,
            $start->chainDepth,
            $start->busyWindowMinutes,
            $start->askingSessionId,
        );

        $this->app->call([$job, 'handle']);
    }

    private function liveConversation(Carbon $updatedAt, FlowSessionStatus $status = FlowSessionStatus::WaitingInput): FlowSession
    {
        $definition = FlowDefinition::query()->where('flow_id', $this->targetFlowId)->sole();

        $session = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => $this->customer->getKey(),
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

    private function targetFlow(): string
    {
        $flowId = (string) Str::uuid();

        FlowDraft::factory()->create([
            'tenant_id'    => self::TENANT_ID,
            'flow_id'      => $flowId,
            'assistant_id' => $this->assistant->getKey(),
        ]);

        $this->definition([
            ['id' => 'hello', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [], $flowId);

        return $flowId;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function definition(array $nodes, array $edges, ?string $flowId = null): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId ?? (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Flow',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);
    }
}

/**
 * Records the starts a node queues instead of queueing them, so a test runs the job on its own, outside the run.
 */
final class RecordingContactFlowStartDispatcher implements ContactFlowStartDispatcherInterface
{
    /** @var list<ContactFlowStart> */
    public array $starts = [];

    public function dispatch(ContactFlowStart $start): void
    {
        $this->starts[] = $start;
    }
}

/**
 * Hides the row a racing duplicate inserts from the first two look-ups by start key (before and under the lock), as
 * when the duplicate lands right before this job's insert.
 */
final class RacingStartKeyRepository implements FlowSessionRepositoryInterface
{
    private int $lookups = 0;

    public function __construct(
        private readonly FlowSessionRepositoryInterface $real,
        private readonly Closure $race,
    ) {
    }

    public function findActiveForContact(Contact $contact, string $assistantId): ?FlowSession
    {
        return $this->real->findActiveForContact($contact, $assistantId);
    }

    public function findById(string $sessionId): ?FlowSession
    {
        return $this->real->findById($sessionId);
    }

    public function findByFireKey(string $triggerId, string $fireKey): ?FlowSession
    {
        return $this->real->findByFireKey($triggerId, $fireKey);
    }

    public function findByStartKey(string $startKey): ?FlowSession
    {
        if (++$this->lookups <= 2) {
            if (2 === $this->lookups) {
                ($this->race)();
            }

            return null;
        }

        return $this->real->findByStartKey($startKey);
    }

    public function create(array $attributes): FlowSession
    {
        return $this->real->create($attributes);
    }

    public function cancel(FlowSession $session): void
    {
        $this->real->cancel($session);
    }
}
