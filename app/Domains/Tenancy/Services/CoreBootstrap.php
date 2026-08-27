<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

/**
 * @see CoreBootstrapInterface
 */
final class CoreBootstrap implements CoreBootstrapInterface
{
    private ?string $bootedForTenantId = null;

    public function __construct(
        private readonly DomainBootstrapper $domainBootstrapper,
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    public function boot(): void
    {
        $tenantId = $this->tenantContext->get()->getId();

        if ($this->bootedForTenantId === $tenantId) {
            return;
        }

        $this->domainBootstrapper->boot();
        $this->bootedForTenantId = $tenantId;
    }

    public function reset(): void
    {
        $this->bootedForTenantId = null;
    }
}
