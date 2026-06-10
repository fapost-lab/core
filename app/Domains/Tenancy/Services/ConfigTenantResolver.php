<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use Illuminate\Http\Request;

final readonly class ConfigTenantResolver implements TenantResolverInterface
{
    /**
     * @param  string|null  $defaultTenantSlug  Config `tenancy.default_tenant_slug`, bound in DomainServiceProvider.
     */
    public function __construct(
        private TenantRepositoryInterface $tenantRepository,
        private ?string $defaultTenantSlug = null,
    ) {
    }

    public function resolve(Request $request): TenantInterface
    {
        $slug = $this->defaultTenantSlug;

        if (empty($slug)) {
            throw new TenantNotFoundException(
                'TENANT_SLUG is not configured. Set TENANT_SLUG in your .env file.'
            );
        }

        $tenant = $this->tenantRepository->findBySlug($slug);

        if (null === $tenant) {
            throw TenantNotFoundException::forSlug($slug);
        }

        if (! $tenant->isActive()) {
            throw new TenantNotActiveException(
                "Tenant [{$slug}] is not active."
            );
        }

        return $tenant;
    }
}
