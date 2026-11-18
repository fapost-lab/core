<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\AutomationContinuationSchedulerInterface;
use App\Jobs\Flow\ContinueAutomationRunJob;

/**
 * Queues the next segment of an automation run on `flow.automations`. Called after the commit that recorded the
 * segment id, so the job always finds the id it carries.
 */
final readonly class QueuedAutomationContinuationScheduler implements AutomationContinuationSchedulerInterface
{
    public function schedule(string $tenantId, string $rootSessionId, string $segmentId): void
    {
        dispatch(new ContinueAutomationRunJob($tenantId, $rootSessionId, $segmentId));
    }
}
