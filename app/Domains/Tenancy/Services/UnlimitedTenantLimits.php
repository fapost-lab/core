<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;

/**
 * Default {@see TenantLimitsInterface}: no limits. An installation without an operator package
 * behaves as if limits did not exist.
 */
final class UnlimitedTenantLimits implements TenantLimitsInterface
{
    public function limitFor(string $tenantId, string $key): ?int
    {
        return null;
    }
}
