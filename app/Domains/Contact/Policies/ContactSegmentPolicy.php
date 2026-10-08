<?php

declare(strict_types=1);

namespace App\Domains\Contact\Policies;

use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see ContactSegment}.
 *
 * Segments are tenant-wide saved filters over contacts, so no assistant assignment applies.
 * They are also the audiences of broadcasts, which is why {@see Permission::ManageBroadcast}
 * counts alongside the contact permissions: the ContentManager role holds ManageBroadcast but
 * not {@see Permission::ManageContacts}, and must still be able to build a broadcast audience.
 * Viewing additionally accepts {@see Permission::ViewContacts}.
 */
final class ContactSegmentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canView($authUser);
    }

    public function view(AuthUser $authUser, ContactSegment $segment): bool
    {
        return $this->canView($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function update(AuthUser $authUser, ContactSegment $segment): bool
    {
        return $this->canManage($authUser);
    }

    public function delete(AuthUser $authUser, ContactSegment $segment): bool
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
                   || $authUser->can(Permission::ManageContacts->value)
                   || $authUser->can(Permission::ManageBroadcast->value));
    }

    private function canManage(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && ($authUser->can(Permission::ManageContacts->value)
                   || $authUser->can(Permission::ManageBroadcast->value));
    }
}
