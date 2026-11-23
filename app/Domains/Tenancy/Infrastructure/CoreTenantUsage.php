<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Services\TenantUsageCounters;
use Fapost\Foundation\Quota\Contracts\TenantUsageInterface;

/**
 * Core's implementation of the tenant usage report: how much of a counted limit a tenant uses.
 *
 * Runs the key's counter inside {@see TenantSwitcher::runForTenant()}, which restores the caller's
 * tenant context also when the counter throws. A tenant that is not reportable
 * ({@see ReportableTenants}) and a key without a counter are not reported: both answer null.
 */
final readonly class CoreTenantUsage implements TenantUsageInterface
{
    public function __construct(
        private TenantUsageCounters $counters,
        private TenantSwitcher $switcher,
        private ReportableTenants $tenants,
    ) {
    }

    public function current(string $tenantId, string $key): ?int
    {
        $counter = $this->counters->find($key);

        if (null === $counter) {
            return null;
        }

        $tenant = $this->tenants->find($tenantId);

        if (null === $tenant) {
            return null;
        }

        return $this->switcher->runForTenant($tenant, $counter);
    }
}
