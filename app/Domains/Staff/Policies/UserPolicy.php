<?php

declare(strict_types=1);

namespace App\Domains\Staff\Policies;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Staff users CRUD and role-hierarchy gates in Filament.
 */
final class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can(Permission::ManageUsers->value);
    }

    public function view(AuthUser $authUser, User $user): bool
    {
        return $authUser->can(Permission::ManageUsers->value);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can(Permission::ManageUsers->value);
    }

    public function update(AuthUser $authUser, User $user): bool
    {
        if ( ! $authUser->can(Permission::ManageUsers->value)) {
            return false;
        }

        if ( ! $authUser instanceof User) {
            return false;
        }

        return ! ($user->isAdmin() && ! $authUser->isAdmin())



        ;
    }

    public function delete(AuthUser $authUser, User $user): bool
    {
        if ( ! $authUser->can(Permission::ManageUsers->value)) {
            return false;
        }

        if ( ! $authUser instanceof User) {
            return false;
        }

        return ! ($user->isAdmin() && ! $authUser->isAdmin())



        ;
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can(Permission::ManageUsers->value);
    }

    public function resendActivation(AuthUser $authUser, User $user): bool
    {
        return $authUser->can(Permission::ManageUsers->value);
    }

    public function deactivate(AuthUser $authUser, User $user): bool
    {
        if ( ! $authUser instanceof User) {
            return false;
        }

        return $authUser->can(Permission::ManageUsers->value)
            && $authUser->isAdmin();
    }

    public function activate(AuthUser $authUser, User $user): bool
    {
        if ( ! $authUser instanceof User) {
            return false;
        }

        return $authUser->can(Permission::ManageUsers->value)
            && $authUser->isAdmin();
    }

    /**
     * Edit profile fields (name, email, phone, password).
     * Always allowed for self; for others requires priority advantage.
     */
    public function updateProfile(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return true;
        }

        return $this->actorOutranks($actor, $target);
    }

    /**
     * Assign roles to another user.
     * Self-assignment is always forbidden. Assigning roles with priority ≥ actor's is forbidden.
     *
     * @param  list<int|string>  $roleIds
     */
    public function updateRoles(User $actor, User $target, array $roleIds = []): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if ($target->isAdmin() && ! $actor->isAdmin()) {
            return false;
        }

        if ( ! $this->actorOutranks($actor, $target)) {
            return false;
        }

        $actorMaxPriority = Role::maxPriority($actor->roles);

        $assignedRoles = Role::query()->whereIn('id', $roleIds)->get();

        foreach ($assignedRoles as $role) {
            if ($role->priority >= $actorMaxPriority) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns true when actor's max role priority is strictly greater than target's.
     */
    private function actorOutranks(User $actor, User $target): bool
    {
        return Role::maxPriority($actor->roles) > Role::maxPriority($target->roles);
    }
}
