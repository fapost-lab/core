<?php

declare(strict_types=1);

namespace App\Domains\Flow\Concurrency;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;

/**
 * Distributed lock primitive for session execution under (tenant, contact, assistant).
 *
 * Implemented directly on Redis (`SET NX EX` + token-checked Lua scripts) rather
 * than Laravel's high-level Lock facade because we need explicit TTL extension
 * for long-running flows ({@see LockHeartbeat}) which the framework Lock API
 * does not expose. Token in payload guards against accidental release by a
 * worker whose heartbeat died and whose claim was taken over by someone else.
 *
 * Default TTL: 30 seconds. Heartbeat refreshes to the same TTL window every
 * 10 seconds while a flow is actively executing.
 *
 * See ADR Message Routing & Concurrency Control § "Distributed Lock Strategy".
 */
final readonly class SessionLockManager
{
    public function __construct(
        private RedisFactory $redis,
        private string $connection = 'default',
    ) {
    }

    /**
     * Try to claim a lock atomically. Returns null if held by someone else.
     */
    public function acquire(LockScope $scope, int $ttlSeconds = 30): ?LockHandle
    {
        $token = Str::uuid()->toString();
        $key   = $scope->key();

        $acquired = (bool) $this->client()->set($key, $token, 'EX', $ttlSeconds, 'NX');

        if ( ! $acquired) {
            return null;
        }

        return new LockHandle($key, $token, $ttlSeconds);
    }

    /**
     * Release the lock, but only if we still own it (token matches).
     * Returns true if released, false if the token had drifted (we no longer
     * own the lock — silent no-op).
     */
    public function release(LockHandle $handle): bool
    {
        $script = <<<'LUA'
        if redis.call("GET", KEYS[1]) == ARGV[1] then
            return redis.call("DEL", KEYS[1])
        else
            return 0
        end
        LUA;

        return 1 === (int) $this->client()->eval($script, 1, $handle->key, $handle->token);
    }

    /**
     * Refresh TTL on a held lock. Returns true if extended, false if we no
     * longer own the lock — caller must treat as ownership-lost signal.
     */
    public function extend(LockHandle $handle, ?int $ttlSeconds = null): bool
    {
        $ttl    = $ttlSeconds ?? $handle->ttlSeconds;
        $script = <<<'LUA'
        if redis.call("GET", KEYS[1]) == ARGV[1] then
            return redis.call("EXPIRE", KEYS[1], ARGV[2])
        else
            return 0
        end
        LUA;

        return 1 === (int) $this->client()->eval($script, 1, $handle->key, $handle->token, $ttl);
    }

    /**
     * Force-delete the lock regardless of ownership. Used by global commands
     * (/reset) that must terminate a stuck session even when the worker
     * holding the lock is unresponsive.
     */
    public function forceRelease(LockScope $scope): void
    {
        $this->client()->del($scope->key());
    }

    private function client(): \Illuminate\Redis\Connections\Connection
    {
        /** @var \Illuminate\Redis\Connections\Connection $connection */
        $connection = $this->redis->connection($this->connection);

        return $connection;
    }
}
