<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Exceptions\TenantNotResolvedException;

/**
 * @see TenantContextInterface
 */
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

    public function reset(): void
    {
        $this->tenant = null;
    }
}
