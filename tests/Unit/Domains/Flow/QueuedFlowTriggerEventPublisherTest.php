<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Events\QueuedFlowTriggerEventPublisher;
use App\Jobs\Flow\DispatchFlowTriggerEventJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class QueuedFlowTriggerEventPublisherTest extends TestCase
{
    public function test_publish_dispatches_dispatch_event_job_with_resolved_payload(): void
    {
        Queue::fake();

        $publisher = new QueuedFlowTriggerEventPublisher();

        $publisher->publish(
            tenantId: 'tenant-A',
            eventName: 'sales.order.created',
            payload: ['order_id' => 'ORD-1', 'total' => 99],
            source: ['session_id' => 'sess-1', 'node_id' => 'emit-1'],
        );

        Queue::assertPushed(DispatchFlowTriggerEventJob::class, fn (DispatchFlowTriggerEventJob $job): bool => 'tenant-A' === $job->tenantId
                && 'sales.order.created' === $job->eventName
                && ['order_id' => 'ORD-1', 'total' => 99] === $job->payload
                && 'sess-1' === $job->source['session_id']
                && 'emit-1' === $job->source['node_id']);

        Queue::assertPushedOn('scheduled.triggers', DispatchFlowTriggerEventJob::class);
    }
}
