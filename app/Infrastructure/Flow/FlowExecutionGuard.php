<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;

final readonly class FlowExecutionGuard implements FlowExecutionGuardInterface
{
    public function __construct(
        private LockProvider $store,
        private int $ttl = 30,
    ) {
    }

    public function run(
        string $tenantId,
        string $contactId,
        string $assistantId,
        Closure $callback,
    ): mixed {
        $key  = "session_lock:{$tenantId}:{$contactId}:{$assistantId}";
        $lock = $this->store->lock($key, $this->ttl);

        if (!$lock->get()) {
            throw new SessionLockTimeoutException($key);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
