<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Events\CoreEventCatalog;
use App\Domains\Flow\Events\QueuedFlowTriggerEventPublisher;
use App\Domains\Flow\Services\EventChainContext;
use App\Jobs\Flow\DispatchFlowTriggerEventJob;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;

final class QueuedFlowTriggerEventPublisherTest extends TestCase
{
    private EventChainContext $chain;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->chain = new EventChainContext();
    }

    public function test_publish_dispatches_dispatch_event_job_with_resolved_payload_an_id_and_depth_one(): void
    {
        $this->publisher()->publish(
            tenantId: 'tenant-A',
            eventName: 'sales.order.created',
            payload: ['order_id' => 'ORD-1', 'total' => 99],
            source: ['session_id' => 'sess-1', 'node_id' => 'emit-1'],
        );

        Queue::assertPushed(DispatchFlowTriggerEventJob::class, fn (DispatchFlowTriggerEventJob $job): bool => 'tenant-A' === $job->tenantId
                && 'sales.order.created' === $job->eventName
                && ['order_id' => 'ORD-1', 'total' => 99] === $job->payload
                && 'sess-1' === $job->source['session_id']
                && 'emit-1' === $job->source['node_id']
                && 26 === mb_strlen((string) $job->eventId)
                && 1 === $job->chainDepth);

        Queue::assertPushedOn('scheduled.triggers', DispatchFlowTriggerEventJob::class);
    }

    public function test_an_event_raised_inside_a_run_is_one_level_deeper_up_to_the_limit(): void
    {
        $this->chain->enter(FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH - 1);

        $this->publisher()->publish('tenant-A', 'loop.ping', [], []);

        Queue::assertPushed(
            DispatchFlowTriggerEventJob::class,
            static fn (DispatchFlowTriggerEventJob $job): bool => FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH === $job->chainDepth,
        );
    }

    public function test_an_event_past_the_limit_is_dropped_with_a_warning(): void
    {
        $this->chain->enter(FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning')
            ->once()
            ->with('flow.event.chain_depth_exceeded', Mockery::on(static fn (array $context): bool => 'loop.ping' === $context['event_name']
                && FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH + 1 === $context['depth']
                && 'sess-5' === $context['session_id']));

        $this->publisher(logger: $logger)->publish('tenant-A', 'loop.ping', [], ['session_id' => 'sess-5']);

        Queue::assertNothingPushed();
    }

    public function test_the_depth_falls_back_when_the_run_that_raised_it_is_left(): void
    {
        $this->chain->enter(FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH);
        $this->chain->leave();

        $this->publisher()->publish('tenant-A', 'loop.ping', [], []);

        Queue::assertPushed(DispatchFlowTriggerEventJob::class, static fn (DispatchFlowTriggerEventJob $job): bool => 1 === $job->chainDepth);
    }

    public function test_a_core_event_nobody_listens_to_queues_nothing(): void
    {
        $this->publisher(listening: false)->publish('tenant-A', CoreEventCatalog::CONTACT_CREATED, ['contact_id' => 'c-1'], ['origin' => 'core']);

        Queue::assertNothingPushed();
    }

    public function test_a_core_event_carries_its_id_and_time_in_the_payload(): void
    {
        $this->publisher(listening: true)->publish(
            'tenant-A',
            CoreEventCatalog::CONTACT_CREATED,
            ['contact_id' => 'c-1', 'platform' => 'telegram', 'language' => 'en'],
            ['origin' => 'core'],
        );

        Queue::assertPushed(DispatchFlowTriggerEventJob::class, static fn (DispatchFlowTriggerEventJob $job): bool => 'c-1' === $job->payload['contact_id']
            && $job->eventId === $job->payload['event_id']
            && is_string($job->payload['occurred_at'] ?? null));
    }

    private function publisher(bool $listening = true, ?LoggerInterface $logger = null): QueuedFlowTriggerEventPublisher
    {
        $triggers = Mockery::mock(FlowTriggerRepositoryInterface::class);
        $triggers->shouldReceive('hasActiveEventTrigger')->andReturn($listening);

        return new QueuedFlowTriggerEventPublisher($this->chain, $triggers, $logger ?? new NullLogger());
    }
}
