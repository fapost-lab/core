<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Exceptions\ConnectionStackEmptyException;
use App\Domains\Tenancy\ValueObjects\MigrationScope;

/**
 * Landlord DDL for tenant schemas and stack-based switching of the default DB connection to a tenant.
 *
 * Application flows should use {@see \App\Domains\Tenancy\Services\TenantSwitcher::runForTenant()} instead of managing
 * {@see switchTo()}/{@see restore()} manually.
 */
interface TenantDatabaseManagerInterface
{
    public function createSchema(TenantInterface $tenant): void;

    public function dropSchema(TenantInterface $tenant): void;

    public function schemaExists(TenantInterface $tenant): bool;

    /**
     * Pushes current default connection/search_path state, then points the tenant connection at this tenant’s schema.
     *
     * Must be paired with {@see restore()} on the same manager instance (singleton stack).
     */
    public function switchTo(TenantInterface $tenant): void;

    /**
     * Pops the stack entry from the last {@see switchTo()} and restores the previous default connection.
     *
     * @throws ConnectionStackEmptyException if no matching {@see switchTo()} was called.
     */
    public function restore(): void;

    /**
     * Runs Laravel migrate against the then-current default connection.
     *
     * Constraint: invoke while tenant DB is active when migrating tenant paths (e.g. inside
     * {@see \App\Domains\Tenancy\Services\TenantSwitcher::runForTenant()}).
     */
    public function runMigrations(MigrationScope $scope): void;
}
