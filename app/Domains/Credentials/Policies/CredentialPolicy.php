<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Policies;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see Credential}.
 *
 * Every ability, listing included, needs {@see Permission::ManageSettings}: a credential is a tenant-level setting
 * that lets flows call outside systems, so only those who manage the tenant's settings see or change it.
 */
final class CredentialPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function view(AuthUser $authUser, Credential $credential): bool
    {
        return $this->canManage($authUser);
    }

    public function create(AuthUser $authUser): bool
    {
        return $this->canManage($authUser);
    }

    public function update(AuthUser $authUser, Credential $credential): bool
    {
        return $this->canManage($authUser);
    }

    public function delete(AuthUser $authUser, Credential $credential): bool
    {
        return $this->canManage($authUser);
    }

    private function canManage(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageSettings->value);
    }
}
