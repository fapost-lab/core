<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use Database\Seeders\RoleSeeder;

/**
 * Thin proxy kept for backward-compatible injection in {@see \App\Domains\Tenancy\Services\TenantProvisioningService}.
 * All logic has moved to {@see RoleSeeder}.
 */
final class AclBootstrapService
{
    public function bootstrap(): void
    {
        (new RoleSeeder())->run();
    }
}
