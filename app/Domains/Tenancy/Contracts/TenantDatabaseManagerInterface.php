<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

interface TenantDatabaseManagerInterface
{
    public function createSchema(TenantInterface $tenant): void;

    public function dropSchema(TenantInterface $tenant): void;

    public function switchTo(TenantInterface $tenant): void;

    public function switchToLandlord(): void;

    public function runMigrations(TenantInterface $tenant): void;

    public function schemaExists(TenantInterface $tenant): bool;
}
