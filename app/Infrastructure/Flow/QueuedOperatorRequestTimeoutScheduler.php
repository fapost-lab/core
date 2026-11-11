<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\OperatorRequestTimeoutSchedulerInterface;
use App\Jobs\Flow\ExpireOperatorRequestJob;
use DateTimeInterface;

/**
 * Schedules an operator request's timeout as a queued job released at `$expiresAt`. Like every deferred resume, it is
 * not queued on a `sync` queue ({@see DeferredResumeDispatcher}): there the wait has no limit.
 */
final readonly class QueuedOperatorRequestTimeoutScheduler implements OperatorRequestTimeoutSchedulerInterface
{
    public function __construct(
        private DeferredResumeDispatcher $dispatcher,
    ) {
    }

    public function schedule(string $tenantId, string $requestId, DateTimeInterface $expiresAt): void
    {
        $this->dispatcher->dispatch(
            new ExpireOperatorRequestJob($tenantId, $requestId),
            $expiresAt,
            ['request_id' => $requestId],
        );
    }
}
