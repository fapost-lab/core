<?php

declare(strict_types=1);

namespace App\Domains\Flow\Events;

use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Jobs\Flow\DispatchFlowTriggerEventJob;

/**
 * Default publisher that hands fanout off to a queued job on the
 * `scheduled.triggers` queue. Calling code returns immediately — the
 * dispatch happens in the background worker.
 */
final readonly class QueuedFlowTriggerEventPublisher implements FlowTriggerEventPublisherInterface
{
    public function publish(string $tenantId, string $eventName, array $payload, array $source): void
    {
        DispatchFlowTriggerEventJob::dispatch($tenantId, $eventName, $payload, $source)
            ->onQueue('scheduled.triggers');
    }
}
