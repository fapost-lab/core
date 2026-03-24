<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Exceptions\TenantNotResolvedException;

final class TenantContext implements TenantContextInterface
{
    private ?TenantInterface $tenant = null;

    public function set(TenantInterface $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): TenantInterface
    {
        return $this->tenant
               ?? throw new TenantNotResolvedException(
                   'Tenant context has not been resolved. Ensure tenant is set before accessing tenant context.'
               );
    }

    public function isResolved(): bool
    {
        return null !== $this->tenant;
    }

    public function runForTenant(TenantInterface $tenant, callable $callback): mixed
    {
        $previousTenant = $this->tenant;

        try {
            $this->tenant = $tenant;

            return $callback();
        } finally {
            $this->tenant = $previousTenant;
        }
    }
}
