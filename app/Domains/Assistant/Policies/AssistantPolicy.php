<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Policies;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

final class AssistantPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canManageAssistants($authUser);
    }

    public function view(AuthUser $authUser, Assistant $assistant): bool
    {
        return $this->canManageAssistants($authUser) && $this->isAssignedOrAdmin($authUser, $assistant);
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->canManageAssistants($authUser);
    }

    public function update(AuthUser $authUser, Assistant $assistant): bool
    {
        return $this->canManageAssistants($authUser) && $this->isAssignedOrAdmin($authUser, $assistant);
    }

    public function delete(AuthUser $authUser, Assistant $assistant): bool
    {
        return $this->canManageAssistants($authUser) && $this->isAssignedOrAdmin($authUser, $assistant);
    }

    /**
     * Bulk delete is only meaningful for admins: per-record {@see delete()} still enforces
     * assignment for non-admins, and the index query is already scoped.
     */
    public function deleteAny(AuthUser $authUser): bool
    {
        return $this->canManageAssistants($authUser)
            && $authUser instanceof User
            && $authUser->isAdmin();
    }

    private function canManageAssistants(AuthUser $authUser): bool
    {
        return $authUser instanceof User
            && $authUser->can(Permission::ManageAssistants->value);
    }

    private function isAssignedOrAdmin(AuthUser $authUser, Assistant $assistant): bool
    {
        if ( ! $authUser instanceof User) {
            return false;
        }

        if ($authUser->isAdmin()) {
            return true;
        }

        return $authUser->assistants()->whereKey($assistant->getKey())->exists();
    }
}
