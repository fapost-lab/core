<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

/**
 * Sets up what a domain keeps for a tenant that has just been provisioned.
 *
 * Called by the provisioning service inside the new tenant's context, after its first admin exists. A provisioning
 * run is resumable, so a call may be repeated for the same tenant and must be safe to repeat. Tenancy sits below the
 * domains, so the domain that cares implements this and Tenancy only calls it.
 */
interface NewTenantInitializerInterface
{
    public function initialize(): void;
}
