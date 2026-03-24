<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Exceptions\TenantNotResolvedException;

/**
 * In-memory active tenant for the current scope (HTTP, job, or nested {@see \App\Domains\Tenancy\Services\TenantSwitcher::runForTenant()}).
 *
 * Does not change the database; full runtime is coordinated by {@see \App\Domains\Tenancy\Services\TenantSwitcher}.
 */
interface TenantContextInterface
{
    public function set(TenantInterface $tenant): void;

    /**
     * Returns the tenant bound to this scope.
     *
     * @throws TenantNotResolvedException when no tenant is set.
     */
    public function get(): TenantInterface;

    public function isResolved(): bool;

    /**
     * Clears the bound tenant so a new scope can start clean (e.g. after {@see \App\Domains\Tenancy\Services\TenantSwitcher} unwinds).
     */
    public function reset(): void;
}
