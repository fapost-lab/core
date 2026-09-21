<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\DelayResumeSchedulerInterface;
use App\Jobs\Flow\ResumeDelayedFlowSessionJob;
use DateTimeInterface;

/**
 * Schedules the delay wake-up as a queued job released at {@code $resumeAt}.
 *
 * The handler calls this before the engine persists the `resume_at` marker, and
 * usually outside any transaction, so `afterCommit()` only helps a caller that
 * wraps the run in one. What keeps the job behind the marker is the delay itself
 * (at least a second) and the session lock the job takes, which the dispatching
 * run holds while it persists. A job that still arrives first finds no matching
 * marker and no-ops. A `sync` queue ignores the delay and cannot resume at all;
 * there the node only moves on with the next inbound message after `resume_at`.
 */
final readonly class QueuedDelayResumeScheduler implements DelayResumeSchedulerInterface
{
    public function schedule(string $tenantId, string $sessionId, string $nodeId, DateTimeInterface $resumeAt): void
    {
        ResumeDelayedFlowSessionJob::dispatch($tenantId, $sessionId, $nodeId, $resumeAt->format(DateTimeInterface::ATOM))
            ->delay($resumeAt)
            ->afterCommit();
    }
}
