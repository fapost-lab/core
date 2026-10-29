<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Fapost\Foundation\Tenancy\Contracts\TenantSelfDeletionPolicyInterface;

/**
 * Default {@see TenantSelfDeletionPolicyInterface}: nobody may delete a workspace from its settings.
 * An installation without an operator package never offers it.
 */
final class NeverAllowsSelfDeletion implements TenantSelfDeletionPolicyInterface
{
    public function allows(string $tenantId): bool
    {
        return false;
    }
}
