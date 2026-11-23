<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use Illuminate\Support\Str;

/**
 * Finds the tenant a usage report is about: the one an operator package may ask for.
 *
 * Tenant ids are UUIDs, so any other form names no tenant and must not reach a `uuid` column. A tenant that is unknown
 * or still provisioning has no storage to count, so it is not reportable and the answer is null.
 */
final readonly class ReportableTenants
{
    public function find(string $tenantId): ?Tenant
    {
        $id = mb_strtolower($tenantId);

        if (! Str::isUuid($id)) {
            return null;
        }

        $tenant = Tenant::on('landlord')->find($id);

        if (! $tenant instanceof Tenant || TenantStatus::Pending === $tenant->status) {
            return null;
        }

        return $tenant;
    }
}
