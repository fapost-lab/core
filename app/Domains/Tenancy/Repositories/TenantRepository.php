<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Repositories;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use InvalidArgumentException;

final class TenantRepository implements TenantRepositoryInterface
{
    public function existsAny(): bool
    {
        return Tenant::on('landlord')->exists();
    }

    public function findById(string $id): ?TenantInterface
    {
        return Tenant::on('landlord')->find($id);
    }

    public function findBySlug(string $slug): ?TenantInterface
    {
        return Tenant::on('landlord')->where('slug', $slug)->first();
    }

    public function getById(string $id): TenantInterface
    {
        return $this->findById($id) ?? throw TenantNotFoundException::forId($id);
    }

    public function findAllActive(): array
    {
        return Tenant::on('landlord')
            ->where('status', TenantStatus::Active)
            ->get()
            ->all();
    }

    public function save(TenantInterface $tenant): void
    {
        if ( ! $tenant instanceof Tenant) {
            throw new InvalidArgumentException(
                sprintf('Expected %s, got %s.', Tenant::class, $tenant::class),
            );
        }

        $tenant->setConnection('landlord');
        $tenant->save();
    }
}
