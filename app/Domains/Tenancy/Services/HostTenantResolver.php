<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use Illuminate\Http\Request;

/**
 * Resolves the tenant named by the request host (`<slug>.<base_domain>`).
 *
 * The Host header chooses the tenant, so everything that is not an active, ordinary
 * tenant slug fails the same way a missing tenant does: a reserved name is never served
 * as a tenant even if a row exists for it.
 */
final readonly class HostTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private TenantRepositoryInterface $tenantRepository,
        private RequestHostClassifier $classifier,
        private TenantSlugPolicy $slugPolicy,
    ) {
    }

    public function resolve(Request $request): TenantInterface
    {
        $host = $this->classifier->classify($request);

        if (! $host->isTenant() || null === $host->slug) {
            throw new TenantNotFoundException("Host [{$request->getHost()}] does not belong to a tenant.");
        }

        $slug = $host->slug;

        if ($this->slugPolicy->isReserved($slug)) {
            throw TenantNotFoundException::forSlug($slug);
        }

        $tenant = $this->tenantRepository->findBySlug($slug);

        if (null === $tenant) {
            throw TenantNotFoundException::forSlug($slug);
        }

        if (! $tenant->isActive()) {
            throw new TenantNotActiveException("Tenant [{$slug}] is not active.");
        }

        return $tenant;
    }
}
