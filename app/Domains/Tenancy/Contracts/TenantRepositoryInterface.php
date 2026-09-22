<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Exceptions\TenantNotFoundException;

interface TenantRepositoryInterface
{
    public function existsAny(): bool;

    public function findById(string $id): ?TenantInterface;

    public function findBySlug(string $slug): ?TenantInterface;

    /**
     * @throws TenantNotFoundException
     */
    public function getById(string $id): TenantInterface;

    /**
     * @return array<int, TenantInterface>
     */
    public function findAllActive(): array;

    /**
     * Tenants whose slug starts with the given prefix, in any status.
     *
     * @return list<TenantInterface>
     */
    public function findBySlugPrefix(string $prefix): array;

    public function save(TenantInterface $tenant): void;

    /**
     * Delete the landlord row. The caller has already removed the tenant's
     * schema and webhook registry entries ({@see \App\Domains\Tenancy\Services\TenantDecommissioner}).
     */
    public function delete(TenantInterface $tenant): void;
}
