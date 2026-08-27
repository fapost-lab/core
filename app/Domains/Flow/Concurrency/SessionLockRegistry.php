<?php

declare(strict_types=1);

namespace App\Domains\Flow\Concurrency;

/**
 * Per-request holder of the session lock currently owned by this worker.
 *
 * Exists for two reasons:
 *
 * 1. **Re-entrancy.** The routing pipeline acquires the lock before it can
 *    classify session state, and {@see \App\Infrastructure\Flow\FlowExecutionGuard}
 *    guards execution for entry points that bypass the router (timeout resume
 *    jobs, sweepers). Both target the same {@see LockScope}; without a shared
 *    slot the inner guard would block on a lock this very worker holds.
 * 2. **Heartbeat.** {@see \App\Domains\Flow\Services\FlowEngine} needs the
 *    {@see LockHandle} to extend the TTL between nodes, but the handle is
 *    acquired several layers above it.
 *
 * Bound as {@code scoped} in the container — one instance per request / queue
 * job, never shared across worker invocations.
 *
 * Octane note: holds plain value objects, lifecycle bounded by request scope,
 * {@see clear()} is idempotent and always called from a finally block.
 *
 * See ADR Message Routing & Concurrency Control § "Distributed Lock Strategy":
 * one lock per (tenant, contact, assistant), held for the whole execution.
 */
final class SessionLockRegistry
{
    private ?LockHandle $handle = null;

    public function set(LockHandle $handle): void
    {
        $this->handle = $handle;
    }

    /**
     * The handle held by this worker, if any. Used by the engine's heartbeat.
     */
    public function current(): ?LockHandle
    {
        return $this->handle;
    }

    /**
     * True when this worker already owns the lock for exactly this scope.
     * Compared by key so a nested guard for a different contact still acquires
     * its own lock rather than piggy-backing on an unrelated one.
     */
    public function holds(LockScope $scope): bool
    {
        return null !== $this->handle && $this->handle->key === $scope->key();
    }

    public function clear(): void
    {
        $this->handle = null;
    }
}
