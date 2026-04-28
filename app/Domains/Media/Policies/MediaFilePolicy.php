<?php

declare(strict_types=1);

namespace App\Domains\Media\Policies;

use App\Domains\Media\Models\MediaFile;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see MediaFile}.
 *
 * Cross-tenant isolation is already enforced by tenant-schema switching at the request
 * boundary — users authenticate inside their own schema and cannot resolve foreign
 * tenant ids via the same connection. This policy adds the role-permission gate on top.
 */
final class MediaFilePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canRead($authUser);
    }

    public function view(AuthUser $authUser, MediaFile $file): bool
    {
        return $this->canRead($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function update(AuthUser $authUser, MediaFile $file): bool
    {
        return $this->canManage($authUser);
    }

    public function delete(AuthUser $authUser, MediaFile $file): bool
    {
        return $this->canManage($authUser);
    }

    public function restore(AuthUser $authUser, MediaFile $file): bool
    {
        return $this->canManage($authUser);
    }

    public function forceDelete(AuthUser $authUser, MediaFile $file): bool
    {
        return $this->canManage($authUser);
    }

    private function canRead(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && (
                   $authUser->can(Permission::ViewMedia->value)
                   || $authUser->can(Permission::ManageMedia->value)
               );
    }

    private function canManage(AuthUser $authUser): bool
    {
        return $authUser instanceof User && $authUser->can(Permission::ManageMedia->value);
    }
}
