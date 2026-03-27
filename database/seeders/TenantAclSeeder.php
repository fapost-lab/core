<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Idempotent ACL bootstrap for the current default connection (tenant schema).
 *
 * For CLI multi-tenant runs use {@see \App\Console\Commands\TenantsSeedAclCommand} (`tenants:seed-acl`).
 */
final class TenantAclSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);
    }
}
