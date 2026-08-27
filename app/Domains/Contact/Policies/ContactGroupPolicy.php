<?php

declare(strict_types=1);

namespace App\Domains\Contact\Policies;

use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see ContactGroup}.
 *
 * Viewing groups requires {@see Permission::ViewContacts}.
 * Mutations require {@see Permission::ManageContacts}, mirroring
 * {@see ContactPolicy}.
 */
final class ContactGroupPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canView($authUser);
    }

    public function view(AuthUser $authUser, ContactGroup $group): bool
    {
        return $this->canView($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function update(AuthUser $authUser, ContactGroup $group): bool
    {
        return $this->canManage($authUser);
    }

    public function delete(AuthUser $authUser, ContactGroup $group): bool
    {
        return $this->canManage($authUser);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    private function canView(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && ($authUser->can(Permission::ViewContacts->value)
                   || $authUser->can(Permission::ManageContacts->value));
    }

    private function canManage(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageContacts->value);
    }
}
