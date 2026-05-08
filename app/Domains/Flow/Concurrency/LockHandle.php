<?php

declare(strict_types=1);

namespace App\Domains\Flow\Concurrency;

/**
 * Opaque ticket returned by {@see SessionLockManager::acquire()} on success.
 *
 * Carries the Redis key, the unique token used to claim the lock, and the
 * current TTL in seconds. All release/extend operations require the token —
 * this prevents a worker whose heartbeat has died from accidentally releasing
 * a lock that's now owned by another worker.
 */
final readonly class LockHandle
{
    public function __construct(
        public string $key,
        public string $token,
        public int $ttlSeconds,
    ) {
    }
}
