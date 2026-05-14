<?php

declare(strict_types=1);

namespace App\Domains\Flow\Policies;

use App\Domains\Flow\Models\FlowLog;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see FlowLog}.
 *
 * Logs are read-only diagnostics — access requires {@see Permission::ViewFlowSessions}.
 * Mutations are always denied; the engine owns the log table.
 */
final class FlowLogPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canView($authUser);
    }

    public function view(AuthUser $authUser, FlowLog $log): bool
    {
        return $this->canView($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, FlowLog $log): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, FlowLog $log): bool
    {
        return false;
    }

    private function canView(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ViewFlowSessions->value);
    }
}
