<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Concurrency\LockAcquisitionPolicy;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Concurrency\SessionLockRegistry;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use Closure;

/**
 * Guards flow execution with the session lock described in ADR Message Routing
 * & Concurrency Control: one lock per (tenant, contact, assistant), held for
 * the whole execution rather than per node.
 *
 * Built on {@see SessionLockManager} — not Laravel's Lock facade — because the
 * heartbeat needs explicit TTL extension, which the framework API does not
 * expose. The manager's token-checked Lua scripts also stop a worker whose
 * claim was taken over from releasing someone else's lock.
 *
 * Re-entrant by design: the routing pipeline acquires the lock before it can
 * classify session state, so by the time the orchestrator calls this guard the
 * lock is usually already held by this very worker. {@see SessionLockRegistry}
 * makes that visible, and the guard passes straight through instead of
 * deadlocking against itself. Entry points that bypass the router — timeout
 * resume jobs, sweepers — find an empty registry and acquire normally.
 */
final readonly class FlowExecutionGuard implements FlowExecutionGuardInterface
{
    public function __construct(
        private LockAcquisitionPolicy $policy,
        private SessionLockManager $manager,
        private SessionLockRegistry $registry,
    ) {
    }

    public function run(
        string $tenantId,
        string $contactId,
        string $assistantId,
        Closure $callback,
    ): mixed {
        $scope = new LockScope($tenantId, $contactId, $assistantId);

        // Already ours (routing pipeline acquired it) — do not re-acquire.
        if ($this->registry->holds($scope)) {
            return $callback();
        }

        $handle = $this->policy->acquireWithRetry($scope);

        if (null === $handle) {
            throw new SessionLockTimeoutException($scope->key());
        }

        // A guard nested under a *different* scope must not orphan the outer
        // claim: the registry holds one slot, so stash whatever was there and
        // put it back on the way out. Without this the outer lock would keep
        // ticking down while the engine heartbeats the inner one.
        $previous = $this->registry->current();
        $this->registry->set($handle);

        try {
            return $callback();
        } finally {
            null === $previous
                ? $this->registry->clear()
                : $this->registry->set($previous);

            $this->manager->release($handle);
        }
    }
}
