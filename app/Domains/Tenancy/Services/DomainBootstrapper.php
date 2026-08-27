<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;

final readonly class DomainBootstrapper
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function boot(): void
    {
        $this->tenantContext->get();
    }
}
