<?php

declare(strict_types=1);

namespace App\Domains\Contact\Policies;

use App\Domains\Contact\Models\Contact;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see Contact}.
 *
 * Viewing contacts requires {@see Permission::ViewContacts}.
 * Mutations require {@see Permission::ManageContacts}; the console uses them only for a
 * contact's tags and groups, and offers no create or edit of a contact itself.
 */
final class ContactPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canView($authUser);
    }

    public function view(AuthUser $authUser, Contact $contact): bool
    {
        return $this->canView($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageContacts->value);
    }

    public function update(AuthUser $authUser, Contact $contact): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageContacts->value);
    }

    public function delete(AuthUser $authUser, Contact $contact): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageContacts->value);
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageContacts->value);
    }

    private function canView(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && ($authUser->can(Permission::ViewContacts->value)
                   || $authUser->can(Permission::ManageContacts->value));
    }
}
