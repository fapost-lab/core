<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Events;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Events\CoreEventCatalog;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Jobs\Flow\ContinueAutomationRunJob;
use App\Jobs\Flow\DispatchFlowTriggerEventJob;
use App\Jobs\Flow\StartAutomationRunJob;
use App\Jobs\Flow\StartFlowFromEventJob;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * Core events start contactless automation runs: once per event id and trigger, with the event's chain depth kept on
 * the session across segments, and an event raised past the depth limit starting nothing — on the contact path too.
 */
#[Group('redis')]
final class CoreEventTriggersTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_a_new_contact_starts_a_contactless_run_on_every_listening_trigger_once_per_event(): void
    {
        Queue::fake();

        $assistant     = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $withAssistant = $this->trigger($this->endFlow(), CoreEventCatalog::CONTACT_CREATED, (string) $assistant->getKey());
        $tenantWide    = $this->trigger($this->endFlow(), CoreEventCatalog::CONTACT_CREATED, null);
        $this->trigger($this->endFlow(), CoreEventCatalog::CONTACT_TAG_ADDED, null);

        $contact = $this->app->make(ContactServiceInterface::class)->findOrCreate(self::TENANT_ID, 'telegram', 'ext-1');

        $dispatch = Queue::pushed(DispatchFlowTriggerEventJob::class)->sole();
        $this->assertSame(1, $dispatch->chainDepth);

        // The fan-out runs twice, as a retry would: every run still starts once.
        $this->app->call([$dispatch, 'handle']);
        $this->app->call([$dispatch, 'handle']);

        $starts = Queue::pushed(StartAutomationRunJob::class);
        $this->assertCount(4, $starts);
        $starts->each(fn (StartAutomationRunJob $job) => $this->app->call([$job, 'handle']));

        $sessions = FlowSession::query()->get()->keyBy('trigger_id');
        $this->assertCount(2, $sessions);

        foreach ([$withAssistant, $tenantWide] as $trigger) {
            /** @var FlowSession $session */
            $session = $sessions[(string) $trigger->getKey()];
            $this->assertNull($session->contact_id);
            $this->assertSame(FlowRunKind::Automation, $session->run_kind);
            $this->assertSame(FlowSessionStatus::Ended, $session->status);
            $this->assertSame($dispatch->eventId, $session->fire_key);
            $this->assertSame(1, $session->event_chain_depth);
            $this->assertSame((string) $contact->getKey(), $session->state['flow']['event']['contact_id']);
            $this->assertSame($dispatch->eventId, $session->state['flow']['event']['event_id']);
            $this->assertSame($trigger->assistant_id, $session->assistant_id);
        }
    }

    public function test_an_automation_run_keeps_its_depth_across_segments(): void
    {
        Queue::fake();
        config(['flow.automation.segment_steps' => 1]);

        $flowId  = $this->flow([$this->emit('emit-a', 'ping.a'), $this->emit('emit-b', 'ping.b'), $this->end()]);
        $trigger = $this->trigger($flowId, CoreEventCatalog::BROADCAST_COMPLETED, null);

        $start = new StartAutomationRunJob(self::TENANT_ID, $flowId, (string) $trigger->getKey(), 'event-1', [], null, 2);
        $this->app->call([$start, 'handle']);

        $handled = 0;
        while (null !== ($job = Queue::pushed(ContinueAutomationRunJob::class)->values()[$handled] ?? null)) {
            ++$handled;
            $this->app->call([$job, 'handle']);
        }

        $this->assertGreaterThan(0, $handled, 'One step a segment: the run continued in other jobs.');
        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->sole()->status);

        $events = Queue::pushed(DispatchFlowTriggerEventJob::class);
        $this->assertSame(['ping.a', 'ping.b'], $events->pluck('eventName')->all());
        $this->assertSame([3, 3], $events->pluck('chainDepth')->all());
    }

    public function test_a_subflow_child_raises_its_events_at_its_parents_depth_plus_one(): void
    {
        Queue::fake();

        $childFlowId = $this->flow([$this->emit('emit-child', 'ping.child'), $this->end()]);
        $parentId    = $this->flow([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
            ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);
        $trigger = $this->trigger($parentId, CoreEventCatalog::BROADCAST_COMPLETED, null);

        $start = new StartAutomationRunJob(self::TENANT_ID, $parentId, (string) $trigger->getKey(), 'event-1', [], null, 2);
        $this->app->call([$start, 'handle']);

        $child = FlowSession::query()->whereNotNull('parent_session_id')->sole();
        $this->assertSame(2, $child->event_chain_depth);

        $event = Queue::pushed(DispatchFlowTriggerEventJob::class)->sole();
        $this->assertSame('ping.child', $event->eventName);
        $this->assertSame(3, $event->chainDepth);
    }

    public function test_a_core_fact_a_node_causes_is_one_level_deeper_than_its_run(): void
    {
        Queue::fake();

        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $flowId    = $this->flow([
            ['id' => 'tag', 'type' => 'set_tag', 'version' => 1, 'config' => ['action' => 'add', 'tags' => ['vip']]],
            $this->end(),
        ]);
        $this->trigger($this->endFlow(), CoreEventCatalog::CONTACT_TAG_ADDED, null);
        $this->reachable($assistant, $contact);

        $job = new StartFlowFromEventJob(self::TENANT_ID, $flowId, (string) $assistant->getKey(), (string) $contact->getKey(), [], 'orders.paid', 2);
        $this->app->call([$job, 'handle']);

        $event = Queue::pushed(DispatchFlowTriggerEventJob::class)->sole();
        $this->assertSame(CoreEventCatalog::CONTACT_TAG_ADDED, $event->eventName);
        $this->assertSame('vip', $event->payload['tag']);
        $this->assertSame(3, $event->chainDepth);
    }

    public function test_the_depth_limit_holds_on_the_contact_path(): void
    {
        Queue::fake();

        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $flowId    = $this->flow([$this->emit('emit-1', 'loop.ping'), $this->end()]);
        $this->reachable($assistant, $contact);

        foreach ([3, FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH] as $depth) {
            $job = new StartFlowFromEventJob(self::TENANT_ID, $flowId, (string) $assistant->getKey(), (string) $contact->getKey(), [], 'loop.ping', $depth);
            $this->app->call([$job, 'handle']);
        }

        $this->assertSame([3, 5], FlowSession::query()->orderBy('event_chain_depth')->pluck('event_chain_depth')->all());

        $events = Queue::pushed(DispatchFlowTriggerEventJob::class);
        $this->assertCount(1, $events, 'The run at the limit raises nothing.');
        $this->assertSame(4, $events->sole()->chainDepth);
    }

    public function test_a_fact_rolled_back_queues_no_event(): void
    {
        $processed = [];
        Event::listen(JobProcessing::class, static function (JobProcessing $event) use (&$processed): void {
            $processed[] = $event->job->resolveName();
        });

        $this->trigger($this->endFlow(), CoreEventCatalog::CONTACT_CREATED, null);

        try {
            DB::transaction(function (): void {
                $this->app->make(ContactServiceInterface::class)->findOrCreate(self::TENANT_ID, 'telegram', 'ext-rolled-back');

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertNotContains(DispatchFlowTriggerEventJob::class, $processed);
        $this->assertSame(0, FlowSession::query()->count());
    }

    public function test_jobs_queued_before_the_id_and_the_depth_existed_still_run(): void
    {
        Queue::fake();

        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->trigger((string) Str::uuid(), 'order.created', (string) $assistant->getKey());

        $dispatch = $this->withoutNewProperties(DispatchFlowTriggerEventJob::class, [
            'tenantId'  => self::TENANT_ID,
            'eventName' => 'order.created',
            'payload'   => ['amount' => 1],
            'source'    => ['contact_id' => 'contact-1'],
        ]);
        $this->app->call([$dispatch, 'handle']);

        $start = Queue::pushed(StartFlowFromEventJob::class)->sole();
        $this->assertSame(1, $start->chainDepth);

        $old = $this->withoutNewProperties(StartAutomationRunJob::class, [
            'tenantId'     => self::TENANT_ID,
            'flowId'       => $this->endFlow(),
            'triggerId'    => (string) Str::uuid(),
            'fireKey'      => 'slot-1',
            'initialState' => [],
            'assistantId'  => null,
        ]);
        $this->app->call([$old, 'handle']);

        $this->assertSame(0, FlowSession::query()->sole()->event_chain_depth);
    }

    /**
     * A job as the previous release serialized it: the properties this change added are left uninitialized, as
     * unserialize leaves them.
     *
     * @template T of object
     *
     * @param  class-string<T>       $class
     * @param  array<string, mixed>  $properties
     *
     * @return T
     */
    private function withoutNewProperties(string $class, array $properties): object
    {
        $reflection = new ReflectionClass($class);
        $job        = $reflection->newInstanceWithoutConstructor();

        foreach ($properties as $name => $value) {
            $reflection->getProperty($name)->setValue($job, $value);
        }

        return $job;
    }

    /**
     * Gives the contact a channel on an active channel of the assistant: an event starts nothing for a contact without one.
     */
    private function reachable(Assistant $assistant, Contact $contact): void
    {
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);
    }

    private function trigger(string $flowId, string $eventName, ?string $assistantId): FlowTrigger
    {
        return FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistantId,
            'flow_id'      => $flowId,
            'type'         => 'event',
            'is_active'    => true,
            'priority'     => 0,
            'config'       => ['event_name' => $eventName],
        ]);
    }

    private function endFlow(): string
    {
        return $this->flow([$this->end()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function emit(string $id, string $eventName): array
    {
        return ['id' => $id, 'type' => 'emit_event', 'version' => 1, 'config' => ['event_type' => $eventName, 'payload' => []]];
    }

    /**
     * @return array<string, mixed>
     */
    private function end(): array
    {
        return ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes  linked in a row, through `success` after an `emit_event` or a `subflow`
     */
    private function flow(array $nodes): string
    {
        $edges = [];

        for ($i = 0; $i < count($nodes) - 1; ++$i) {
            $edges[] = ['id' => "e{$i}", 'from' => $nodes[$i]['id'], 'to' => $nodes[$i + 1]['id'], 'handle' => in_array($nodes[$i]['type'], ['emit_event', 'subflow'], true) ? 'success' : 'default'];
        }

        $flowId = (string) Str::uuid();

        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Core event flow',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);

        return $flowId;
    }
}
