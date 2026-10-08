<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Foundation\Tenancy\DTO\TenantAccessState;

/**
 * Default {@see TenantAccessModeInterface}: every tenant is active and has nothing to be told.
 * An installation without an operator package behaves as if access modes did not exist.
 */
final class AlwaysActiveAccessMode implements TenantAccessModeInterface
{
    public function stateFor(string $tenantId): TenantAccessState
    {
        return TenantAccessState::active();
    }
}
