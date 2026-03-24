<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Exceptions\ConnectionStackEmptyException;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Closure;

interface TenantDatabaseManagerInterface
{
    public function createSchema(TenantInterface $tenant): void;

    public function dropSchema(TenantInterface $tenant): void;

    public function schemaExists(TenantInterface $tenant): bool;

    /**
     * Low-level primitive. Prefer runForTenant().
     * Pushes current connection onto stack before switching.
     */
    public function switchTo(TenantInterface $tenant): void;

    /**
     * Pop previous connection from stack and restore it.
     *
     * @throws ConnectionStackEmptyException if stack is empty
     */
    public function restore(): void;

    /**
     * Execute callback in tenant context.
     * Guarantees restore() via finally — safe for exceptions and early returns.
     */
    public function runForTenant(TenantInterface $tenant, Closure $callback): mixed;

    public function runMigrations(MigrationScope $scope): void;
}
