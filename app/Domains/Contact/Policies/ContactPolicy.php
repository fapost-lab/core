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
 * Mutations (future operators UI) require {@see Permission::ManageContacts}.
 * The current Filament resource is read-only — create/update are denied at the
 * resource level regardless of this policy.
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
