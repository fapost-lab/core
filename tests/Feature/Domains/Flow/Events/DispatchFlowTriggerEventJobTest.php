<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Events;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Jobs\Flow\DispatchFlowTriggerEventJob;
use App\Jobs\Flow\StartFlowFromEventJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Covers event fanout: a published event resolves every active, subscribed
 * event trigger and fans out one StartFlowFromEventJob per trigger — the step
 * that was previously only logged.
 */
final class DispatchFlowTriggerEventJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_fans_out_a_start_job_per_subscribed_trigger(): void
    {
        Bus::fake();

        $flowA = (string) Str::uuid();
        $flowB = (string) Str::uuid();

        $this->eventTrigger($flowA, 'order.created');
        $this->eventTrigger($flowB, 'order.created');
        $this->eventTrigger((string) Str::uuid(), 'order.created', active: false);   // inactive → ignored
        $this->eventTrigger((string) Str::uuid(), 'order.shipped');                   // other event → ignored

        $this->runDispatch('order.created', ['amount' => 42], contactId: 'contact-9');

        Bus::assertDispatchedTimes(StartFlowFromEventJob::class, 2);
        Bus::assertDispatched(
            StartFlowFromEventJob::class,
            static fn (StartFlowFromEventJob $job): bool => $job->flowId === $flowA
                && 'contact-9' === $job->contactId
                && 'order.created' === $job->eventName
                && ['amount' => 42] === $job->payload,
        );
    }

    public function test_each_start_carries_its_trigger_the_event_id_and_the_emitting_session(): void
    {
        Bus::fake();

        $trigger = $this->eventTrigger((string) Str::uuid(), 'order.created');
        $source  = ['tenant_id' => self::TENANT_ID, 'session_id' => 'sess-1', 'node_id' => 'emit-1', 'contact_id' => 'contact-9'];
        $job     = new DispatchFlowTriggerEventJob(self::TENANT_ID, 'order.created', [], $source, 'event-42');
        app()->call([$job, 'handle']);

        Bus::assertDispatched(
            StartFlowFromEventJob::class,
            static fn (StartFlowFromEventJob $start): bool => $start->triggerId === $trigger->id
                && 'event-42' === $start->eventId
                && 'sess-1' === $start->sourceSessionId,
        );
    }

    public function test_skips_fanout_when_event_has_no_originating_contact(): void
    {
        Bus::fake();

        $this->eventTrigger((string) Str::uuid(), 'order.created');

        $this->runDispatch('order.created', [], contactId: null);

        Bus::assertNotDispatched(StartFlowFromEventJob::class);
    }

    public function test_an_assistant_filter_starts_only_the_triggers_that_list_the_emitting_sessions_assistant(): void
    {
        Bus::fake();

        $sales   = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $support = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        $listsSales   = (string) Str::uuid();
        $listsSupport = (string) Str::uuid();
        $noFilter     = (string) Str::uuid();

        $this->eventTrigger($listsSales, 'order.created', assistantFilter: [(string) $sales->getKey()]);
        $this->eventTrigger($listsSupport, 'order.created', assistantFilter: [(string) $support->getKey()]);
        $this->eventTrigger($noFilter, 'order.created');

        $this->runDispatch('order.created', [], contactId: 'contact-9', sessionId: $this->sessionOf($sales));

        $started = [];
        Bus::assertDispatched(StartFlowFromEventJob::class, static function (StartFlowFromEventJob $job) use (&$started): bool {
            $started[] = $job->flowId;

            return true;
        });

        sort($started);
        $expected = [$listsSales, $noFilter];
        sort($expected);
        $this->assertSame($expected, $started, 'The support-only trigger must not start on an event of the sales assistant.');
    }

    public function test_an_event_emitted_by_a_subflow_child_is_filtered_by_the_parents_assistant(): void
    {
        Bus::fake();

        $sales   = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $support = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        $listsSales   = (string) Str::uuid();
        $listsSupport = (string) Str::uuid();

        $this->eventTrigger($listsSales, 'order.created', assistantFilter: [(string) $sales->getKey()]);
        $this->eventTrigger($listsSupport, 'order.created', assistantFilter: [(string) $support->getKey()]);

        $this->runDispatch('order.created', [], contactId: 'contact-9', sessionId: $this->childSessionOf($this->sessionOf($sales)));

        Bus::assertDispatchedTimes(StartFlowFromEventJob::class, 1);
        Bus::assertDispatched(StartFlowFromEventJob::class, static fn (StartFlowFromEventJob $job): bool => $job->flowId === $listsSales);
    }

    public function test_an_event_whose_session_is_unknown_starts_only_the_triggers_without_a_filter(): void
    {
        Bus::fake();

        $sales    = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $noFilter = (string) Str::uuid();

        $this->eventTrigger((string) Str::uuid(), 'order.created', assistantFilter: [(string) $sales->getKey()]);
        $this->eventTrigger($noFilter, 'order.created');

        $this->runDispatch('order.created', [], contactId: 'contact-9', sessionId: (string) Str::uuid());

        Bus::assertDispatchedTimes(StartFlowFromEventJob::class, 1);
        Bus::assertDispatched(StartFlowFromEventJob::class, static fn (StartFlowFromEventJob $job): bool => $job->flowId === $noFilter);
    }

    private function sessionOf(Assistant $assistant): string
    {
        $contact = Contact::factory()->create(['tenant_id' => self::TENANT_ID]);
        $flowId  = (string) Str::uuid();

        $definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Emitting flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        return (string) FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'emit-1',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 0,
        ])->getKey();
    }

    /**
     * A child session as the subflow starter creates it: the parent's tenant, assistant and contact, linked to the parent.
     */
    private function childSessionOf(string $parentSessionId): string
    {
        $parent = FlowSession::query()->findOrFail($parentSessionId);

        return (string) FlowSession::query()->create([
            'tenant_id'             => $parent->tenant_id,
            'assistant_id'          => $parent->assistant_id,
            'contact_id'            => $parent->contact_id,
            'flow_definition_id'    => $parent->flow_definition_id,
            'flow_version'          => 1,
            'current_node_id'       => 'emit-1',
            'parent_session_id'     => $parentSessionId,
            'parent_resume_node_id' => 'subflow-1',
            'state'                 => [],
            'status'                => FlowSessionStatus::Active,
            'version'               => 0,
        ])->getKey();
    }

    private function runDispatch(string $eventName, array $payload, ?string $contactId, string $sessionId = 'sess-1'): void
    {
        $source = ['tenant_id' => self::TENANT_ID, 'session_id' => $sessionId, 'node_id' => 'emit-1'];
        if (null !== $contactId) {
            $source['contact_id'] = $contactId;
        }

        $job = new DispatchFlowTriggerEventJob(self::TENANT_ID, $eventName, $payload, $source);
        app()->call([$job, 'handle']);
    }

    /**
     * @param  list<string>|null  $assistantFilter
     */
    private function eventTrigger(string $flowId, string $eventName, bool $active = true, ?array $assistantFilter = null): FlowTrigger
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        return FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => (string) $assistant->getKey(),
            'flow_id'      => $flowId,
            'type'         => 'event',
            'is_active'    => $active,
            'priority'     => 0,
            'config'       => null === $assistantFilter
                ? ['event_name' => $eventName]
                : ['event_name' => $eventName, 'assistant_filter' => $assistantFilter],
        ]);
    }
}
