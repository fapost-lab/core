<?php

declare(strict_types=1);

namespace App\Domains\Solution\Infrastructure;

use App\Domains\Solution\Contracts\SolutionGrantSnapshotStoreInterface;
use App\Domains\Tenancy\Contracts\TenantCacheForgetterInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;

/**
 * Forgets the Solution grants kept for a tenant (a day's cache of the operator's last answer) when the tenant is
 * removed completely.
 */
final readonly class SolutionGrantCacheForgetter implements TenantCacheForgetterInterface
{
    public function __construct(private SolutionGrantSnapshotStoreInterface $snapshots)
    {
    }

    public function forget(TenantInterface $tenant): void
    {
        $this->snapshots->forget($tenant->getId());
    }
}
