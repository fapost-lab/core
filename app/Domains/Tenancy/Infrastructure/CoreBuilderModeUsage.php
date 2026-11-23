<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Closure;
use Fapost\Foundation\Analytics\Contracts\BuilderModeUsageInterface;
use Fapost\Foundation\Analytics\DTO\BuilderModeUsage;
use Illuminate\Support\Str;

/**
 * Core's implementation of the builder mode usage report: how many staff users of a tenant are on each builder mode.
 *
 * Tenancy finds the tenant and switches to it; the count itself belongs to the staff domain and is handed in as a
 * closure by the service provider, so Tenancy takes no dependency on Staff. The closure runs inside
 * {@see TenantSwitcher::runForTenant()}, which restores the caller's tenant context also when it throws. A tenant
 * that is unknown or still provisioning has no storage to count and answers null.
 */
final readonly class CoreBuilderModeUsage implements BuilderModeUsageInterface
{
    /**
     * @param  Closure(): array{tree: int, graph: int}  $count  counts the current tenant's staff users per mode
     */
    public function __construct(
        private TenantSwitcher $switcher,
        private Closure $count,
    ) {
    }

    public function usageFor(string $tenantId): ?BuilderModeUsage
    {
        $id = mb_strtolower($tenantId);

        // Tenant ids are UUIDs; any other form names no tenant and must not reach a `uuid` column.
        if (! Str::isUuid($id)) {
            return null;
        }

        $tenant = Tenant::on('landlord')->find($id);

        if (! $tenant instanceof Tenant || TenantStatus::Pending === $tenant->status) {
            return null;
        }

        /** @var array{tree: int, graph: int} $counts */
        $counts = $this->switcher->runForTenant($tenant, $this->count);

        return new BuilderModeUsage(tree: $counts['tree'], graph: $counts['graph']);
    }
}
