<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Models\TenantStatus;
use Carbon\CarbonInterface;

/**
 * What provisioning needs to know about a tenant row: the tenant itself, its status, whether it
 * has claimed its schema and until when another run holds the provisioning lease.
 */
final readonly class TenantProvisioningState
{
    public function __construct(
        public TenantInterface $tenant,
        public TenantStatus $status,
        public bool $schemaClaimed,
        public ?CarbonInterface $leaseUntil,
    ) {
    }

    public function isLeased(CarbonInterface $now): bool
    {
        return null !== $this->leaseUntil && $this->leaseUntil->greaterThanOrEqualTo($now);
    }
}
