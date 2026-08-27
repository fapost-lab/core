<?php

declare(strict_types=1);

namespace App\Domains\Flow\Concurrency;

/**
 * Refreshes a held session lock so it survives long-running execution
 * (e.g. a `call` node hitting a slow upstream API). Intended to be invoked
 * by the routing pipeline at a steady cadence (default every 10 seconds);
 * scheduling itself is the caller's concern (timer / co-routine / async task).
 *
 * If the underlying lock drifts (token mismatch — heartbeat hung long enough
 * that another worker claimed the slot), {@see extend()} returns false and
 * the caller must abandon the current execution: optimistic-lock conflict on
 * `flow_sessions.version` will catch any pending writes as an additional
 * safety net.
 */
final readonly class LockHeartbeat
{
    public function __construct(
        private SessionLockManager $manager,
        private int $intervalSeconds = 10,
        private int $extendToSeconds = 30,
    ) {
    }

    public function intervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    /**
     * Issue one extend tick. Returns false if ownership has been lost.
     */
    public function extend(LockHandle $handle): bool
    {
        return $this->manager->extend($handle, $this->extendToSeconds);
    }
}
