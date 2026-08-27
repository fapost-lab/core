<?php

declare(strict_types=1);

namespace App\Domains\Flow\Policies;

use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see FlowGroup}.
 *
 * Full CRUD requires {@see Permission::ManageFlowGroups}.
 */
final class FlowGroupPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function view(AuthUser $authUser, FlowGroup $group): bool
    {
        return $this->canManage($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function update(AuthUser $authUser, FlowGroup $group): bool
    {
        return $this->canManage($authUser);
    }

    public function delete(AuthUser $authUser, FlowGroup $group): bool
    {
        return $this->canManage($authUser);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    private function canManage(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageFlowGroups->value);
    }
}
