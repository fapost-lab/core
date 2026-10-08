<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Fapost\Foundation\Tenancy\DTO\TenantAccessState;

/**
 * The access state of the tenant the current request runs in.
 *
 * Bound `scoped`. {@see TenantRequestRunner} asks the operator once when it enters the tenant and
 * stores the answer here, so the write guard, the banner and the shared Inertia props read it
 * without asking again. Outside a tenant request nothing is stored and the state is active.
 */
final class CurrentAccessState
{
    private ?TenantAccessState $state = null;

    public function set(TenantAccessState $state): void
    {
        $this->state = $state;
    }

    public function get(): TenantAccessState
    {
        return $this->state ?? TenantAccessState::active();
    }

    public function clear(): void
    {
        $this->state = null;
    }
}
