<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use App\Domains\Shared\Infrastructure\RedisKeyPurger;
use App\Domains\Tenancy\Contracts\TenantCacheForgetterInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;

/**
 * Forgets the `call` ledger records of a tenant (`flow:call:{tenant}:*`, response bodies kept for up to the dedup
 * window) when the tenant is removed completely, through {@see RedisKeyPurger} (SCAN, then UNLINK in batches).
 * Safe to repeat.
 */
final readonly class CallLedgerCacheForgetter implements TenantCacheForgetterInterface
{
    public function __construct(private RedisKeyPurger $purger)
    {
    }

    public function forget(TenantInterface $tenant): void
    {
        $this->purger->purge(RedisCallExecutionLedger::tenantKeyPrefix((string) $tenant->getId()) . '*');
    }
}
