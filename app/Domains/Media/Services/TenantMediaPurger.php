<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantMediaPurgerInterface;

/**
 * Deletes the media objects the installation holds for a tenant, when the tenant itself is being removed.
 *
 * Every key of a tenant starts with `tenants/{id}/` (see {@see \App\Domains\Media\Storage\StoragePathFactory}), so the
 * whole prefix goes with one directory delete on the installation's disk. A tenant's own storage (a client bucket) is
 * never reached: it belongs to the client, not to the installation. Safe to repeat: an empty prefix is not an error.
 */
final readonly class TenantMediaPurger implements TenantMediaPurgerInterface
{
    public function __construct(private TenantMediaDisk $disks)
    {
    }

    /**
     * @return bool true when the tenant keeps its media in storage of its own, which stays untouched
     */
    public function purge(TenantInterface $tenant): bool
    {
        $this->disks->installation()->deleteDirectory('tenants/' . $tenant->getId());

        return $this->disks->hasOwnStorage($tenant);
    }
}
