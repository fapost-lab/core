<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Concurrency\LockScope;
use Closure;

interface FlowExecutionGuardInterface
{
    /**
     * Runs {@code $callback} under the conversation lock of (tenant, contact, assistant).
     */
    public function run(
        string $tenantId,
        string $contactId,
        string $assistantId,
        Closure $callback,
    ): mixed;

    /**
     * Runs {@code $callback} under the lock of {@code $scope} — for a session, {@see LockScope::ofSession()}, which
     * gives a contactless run its trigger's lock.
     *
     * @throws \App\Domains\Flow\Exceptions\SessionLockTimeoutException when the lock stays busy
     */
    public function runInScope(LockScope $scope, Closure $callback): mixed;
}
