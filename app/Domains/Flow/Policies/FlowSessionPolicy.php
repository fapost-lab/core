<?php

declare(strict_types=1);

namespace App\Domains\Flow\Policies;

use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see FlowSession}.
 *
 * Sessions are engine-owned and read-only in the UI — only
 * {@see Permission::ViewFlowSessions} is required; mutations are denied.
 */
final class FlowSessionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canView($authUser);
    }

    public function view(AuthUser $authUser, FlowSession $session): bool
    {
        return $this->canView($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return false;
    }

    public function update(AuthUser $authUser, FlowSession $session): bool
    {
        return false;
    }

    public function delete(AuthUser $authUser, FlowSession $session): bool
    {
        return false;
    }

    private function canView(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ViewFlowSessions->value);
    }
}
