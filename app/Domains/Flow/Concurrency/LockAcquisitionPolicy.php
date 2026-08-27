<?php

declare(strict_types=1);

namespace App\Domains\Flow\Concurrency;

/**
 * Bounded retry strategy for acquiring a session lock against transient
 * contention. Used by the message routing pipeline (Phase D-1) before
 * dropping the message with a busy notice.
 *
 * Default policy from ADR Message Routing: 3 attempts × 2 seconds delay.
 * Worker re-queues the job into the Horizon backoff queue rather than
 * busy-waiting beyond this budget.
 */
final readonly class LockAcquisitionPolicy
{
    public function __construct(
        private SessionLockManager $manager,
        private int $maxAttempts = 3,
        private int $retryDelayMs = 2000,
        private int $ttlSeconds = 30,
    ) {
    }

    /**
     * Attempt acquisition with bounded retries. Returns null on exhaustion —
     * caller is responsible for the drop policy (busy notice, requeue, etc).
     */
    public function acquireWithRetry(LockScope $scope): ?LockHandle
    {
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            $handle = $this->manager->acquire($scope, $this->ttlSeconds);

            if (null !== $handle) {
                return $handle;
            }

            if ($attempt < $this->maxAttempts) {
                usleep($this->retryDelayMs * 1000);
            }
        }

        return null;
    }
}
