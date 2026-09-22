<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\SendMessageTimeoutSchedulerInterface;
use App\Jobs\Flow\ResumeTimedOutSendMessageNodeJob;
use DateTimeInterface;

/**
 * Schedules a `send_message` timeout as a queued job released at {@code $timeoutAt};
 * the job resumes the session under its lock and no-ops if the node was answered.
 */
final readonly class QueuedSendMessageTimeoutScheduler implements SendMessageTimeoutSchedulerInterface
{
    public function __construct(
        private DeferredResumeDispatcher $dispatcher,
    ) {
    }

    public function schedule(
        string $tenantId,
        string $sessionId,
        string $nodeId,
        string $platform,
        DateTimeInterface $timeoutAt,
    ): void {
        $this->dispatcher->dispatch(
            new ResumeTimedOutSendMessageNodeJob($tenantId, $sessionId, $nodeId, $platform),
            $timeoutAt,
            ['session_id' => $sessionId, 'node_id' => $nodeId],
        );
    }
}
