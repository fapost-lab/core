<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Services\TenantUsageCounters;
use Fapost\Foundation\Quota\Contracts\TenantUsageInterface;
use Illuminate\Support\Str;

/**
 * Core's implementation of the tenant usage report: how much of a counted limit a tenant uses.
 *
 * Runs the key's counter inside {@see TenantSwitcher::runForTenant()}, which restores the caller's
 * tenant context also when the counter throws. A tenant that is unknown or still provisioning has
 * no storage to count, and a key without a counter is not reported: both answer null.
 */
final readonly class CoreTenantUsage implements TenantUsageInterface
{
    public function __construct(
        private TenantUsageCounters $counters,
        private TenantSwitcher $switcher,
    ) {
    }

    public function current(string $tenantId, string $key): ?int
    {
        $counter = $this->counters->find($key);

        if (null === $counter) {
            return null;
        }

        $id = mb_strtolower($tenantId);

        // Tenant ids are UUIDs; any other form names no tenant and must not reach a `uuid` column.
        if (! Str::isUuid($id)) {
            return null;
        }

        $tenant = Tenant::on('landlord')->find($id);

        if (! $tenant instanceof Tenant || TenantStatus::Pending === $tenant->status) {
            return null;
        }

        return $this->switcher->runForTenant($tenant, $counter);
    }
}
