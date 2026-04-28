<?php

declare(strict_types=1);

namespace App\Domains\Media\Policies;

use App\Domains\Media\Models\MediaFolder;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see MediaFolder}. See {@see MediaFilePolicy} on tenant
 * isolation reasoning.
 */
final class MediaFolderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canRead($authUser);
    }

    public function view(AuthUser $authUser, MediaFolder $folder): bool
    {
        return $this->canRead($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function update(AuthUser $authUser, MediaFolder $folder): bool
    {
        return $this->canManage($authUser);
    }

    public function delete(AuthUser $authUser, MediaFolder $folder): bool
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
