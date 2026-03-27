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

    public function save(TenantInterface $tenant): void;
}
