<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Repositories;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use InvalidArgumentException;

/**
 * Landlord-backed repository for {@see Tenant} aggregates.
 *
 * All queries are explicitly performed on the `landlord` connection.
 */
final class TenantRepository implements TenantRepositoryInterface
{
    /**
     * Check if at least one tenant exists.
     */
    public function existsAny(): bool
    {
        return Tenant::on('landlord')->exists();
    }

    /**
     * Find tenant by id or return null.
     */
    public function findById(string $id): ?TenantInterface
    {
        return Tenant::on('landlord')->find($id);
    }

    /**
     * Find tenant by slug or return null.
     */
    public function findBySlug(string $slug): ?TenantInterface
    {
        return Tenant::on('landlord')->where('slug', $slug)->first();
    }

    /**
     * Get tenant by id or throw {@see TenantNotFoundException}.
     */
    public function getById(string $id): TenantInterface
    {
        return $this->findById($id) ?? throw TenantNotFoundException::forId($id);
    }

    /**
     * Return all active tenants.
     *
     * @return list<TenantInterface>
     */
    public function findAllActive(): array
    {
        return Tenant::on('landlord')
            ->where('status', TenantStatus::Active)
            ->get()
            ->all();
    }

    /**
     * Persist tenant on landlord connection.
     *
     * @throws InvalidArgumentException When given non-Eloquent implementation.
     */
    public function save(TenantInterface $tenant): void
    {
        if (!$tenant instanceof Tenant) {
            throw new InvalidArgumentException(
                sprintf('Expected %s, got %s.', Tenant::class, $tenant::class),
            );
        }

        $tenant->setConnection('landlord');
        $tenant->save();
    }
}
