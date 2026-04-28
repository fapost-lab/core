<?php

declare(strict_types=1);

namespace App\Domains\Staff\Policies;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class RolePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->isAdminWithUserManagement($authUser);
    }

    private function isAdminWithUserManagement(AuthUser $authUser): bool
    {
        if (!$authUser instanceof User) {
            return false;
        }

        return $authUser->can(Permission::ManageUsers->value) && $authUser->isAdmin();
    }

    public function view(AuthUser $authUser, Role $role): bool
    {
        return $this->isAdminWithUserManagement($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->isAdminWithUserManagement($authUser);
    }

    public function update(AuthUser $authUser, Role $role): bool
    {
        return $this->isAdminWithUserManagement($authUser);
    }

    public function delete(AuthUser $authUser, Role $role): bool
    {
        if ($role->isSystemRole()) {
            return false;
        }

        return $this->isAdminWithUserManagement($authUser);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $this->isAdminWithUserManagement($authUser);
    }
}
