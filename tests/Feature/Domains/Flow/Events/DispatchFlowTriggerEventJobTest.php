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
