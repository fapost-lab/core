<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

/**
 * Deletes the media objects the installation holds for a tenant that is being removed completely. Implemented by the
 * media domain. A tenant's own storage (a client bucket) is never touched. Safe to repeat.
 */
interface TenantMediaPurgerInterface
{
    /**
     * @return bool true when the tenant keeps its media in storage of its own, which stays untouched
     */
    public function purge(TenantInterface $tenant): bool;
}
