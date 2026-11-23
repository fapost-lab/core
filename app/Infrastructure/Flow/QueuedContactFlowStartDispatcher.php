<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\ContactFlowStartDispatcherInterface;
use App\Domains\Flow\DTOs\ContactFlowStart;
use App\Jobs\Flow\StartFlowForContactJob;

/**
 * Queues {@see StartFlowForContactJob} on `scheduled.triggers`, after the asking step's transaction commits: a step
 * rolled back starts nothing.
 */
final readonly class QueuedContactFlowStartDispatcher implements ContactFlowStartDispatcherInterface
{
    public function dispatch(ContactFlowStart $start): void
    {
        StartFlowForContactJob::dispatch(
            $start->tenantId,
            $start->flowId,
            $start->contactId,
            $start->input,
            $start->startKey,
            $start->chainDepth,
            $start->busyWindowMinutes,
            $start->askingSessionId,
        )->afterCommit();
    }
}
