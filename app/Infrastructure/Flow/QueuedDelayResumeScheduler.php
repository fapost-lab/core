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
 * marker and no-ops. A `sync` queue cannot defer at all, so nothing is queued
 * there ({@see DeferredResumeDispatcher}) and the node only moves on with the next
 * inbound message after `resume_at`.
 */
final readonly class QueuedDelayResumeScheduler implements DelayResumeSchedulerInterface
{
    public function __construct(
        private DeferredResumeDispatcher $dispatcher,
    ) {
    }

    public function schedule(string $tenantId, string $sessionId, string $nodeId, DateTimeInterface $resumeAt): void
    {
        $this->dispatcher->dispatch(
            new ResumeDelayedFlowSessionJob($tenantId, $sessionId, $nodeId, $resumeAt->format(DateTimeInterface::ATOM)),
            $resumeAt,
            ['session_id' => $sessionId, 'node_id' => $nodeId],
        );
    }
}
