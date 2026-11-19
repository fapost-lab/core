<?php

declare(strict_types=1);

namespace App\Domains\Flow\Events;

use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Services\EventChainContext;
use App\Jobs\Flow\DispatchFlowTriggerEventJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Default publisher that hands fanout off to a queued job on the `scheduled.triggers` queue, dispatched after the
 * caller's transaction commits. Calling code returns immediately — the dispatch happens in the background worker.
 *
 * Here, before anything is queued, the event gets its id and its chain depth, and an event past
 * {@see FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH} is dropped: no job, no session.
 */
final readonly class QueuedFlowTriggerEventPublisher implements FlowTriggerEventPublisherInterface
{
    public function __construct(
        private EventChainContext $eventChain,
        private FlowTriggerRepositoryInterface $triggers,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(string $tenantId, string $eventName, array $payload, array $source): void
    {
        $eventId = (string) Str::ulid();

        $core = CoreEventCatalog::has($eventName);

        // Every new contact and every tag would otherwise queue an empty job — or log a depth nobody cares about.
        if ($core && ! $this->triggers->hasActiveEventTrigger($tenantId, $eventName)) {
            return;
        }

        $depth = $this->eventChain->current() + 1;

        if ($depth > self::MAX_CHAIN_DEPTH) {
            $this->logger->warning('flow.event.chain_depth_exceeded', [
                'tenant_id'  => $tenantId,
                'event_name' => $eventName,
                'depth'      => $depth,
                'max_depth'  => self::MAX_CHAIN_DEPTH,
                'session_id' => $source['session_id'] ?? null,
                'node_id'    => $source['node_id'] ?? null,
            ]);

            return;
        }

        if ($core) {
            $payload = [
                ...$payload,
                'event_id'    => $eventId,
                'occurred_at' => Carbon::now()->toIso8601String(),
            ];
        }

        DispatchFlowTriggerEventJob::dispatch($tenantId, $eventName, $payload, $source, $eventId, $depth)
            ->onQueue('scheduled.triggers')
            ->afterCommit();
    }
}
