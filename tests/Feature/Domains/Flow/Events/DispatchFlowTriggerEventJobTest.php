<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Events;

use App\Domains\Assistant\Models\Assistant;
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

    private function runDispatch(string $eventName, array $payload, ?string $contactId): void
    {
        $source = ['tenant_id' => self::TENANT_ID, 'session_id' => 'sess-1', 'node_id' => 'emit-1'];
        if (null !== $contactId) {
            $source['contact_id'] = $contactId;
        }

        $job = new DispatchFlowTriggerEventJob(self::TENANT_ID, $eventName, $payload, $source);
        app()->call([$job, 'handle']);
    }

    private function eventTrigger(string $flowId, string $eventName, bool $active = true): FlowTrigger
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        return FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => (string) $assistant->getKey(),
            'flow_id'      => $flowId,
            'type'         => 'event',
            'is_active'    => $active,
            'priority'     => 0,
            'config'       => ['event_name' => $eventName],
        ]);
    }
}
