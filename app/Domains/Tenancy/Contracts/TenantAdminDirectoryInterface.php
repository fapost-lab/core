<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\ValueObjects\TenantAdmin;

/**
 * Who counts as an administrator of the tenant whose context is current. Implemented by the staff domain, which owns
 * the roles; Tenancy asks it and never reads a role itself.
 */
interface TenantAdminDirectoryInterface
{
    /**
     * The person, when the id names an active administrator of the current tenant; null for anyone else, the
     * platform support user included.
     */
    public function find(string $userId): ?TenantAdmin;
}
