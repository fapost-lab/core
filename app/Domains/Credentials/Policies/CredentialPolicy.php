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
 * The console screen's abilities, listing included, need {@see Permission::ManageCredentials}: a credential decides
 * which outside systems the tenant's flows may call with a secret. {@see self::use()} is the builder's: picking a
 * saved credential for a `call` node and testing a call with it, without ever seeing the value, needs
 * {@see Permission::UseCredentials}.
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

    /**
     * Whether the user may bind a saved credential to a `call` node, list the credentials to pick from, and test a
     * call with one. The value is never shown.
     */
    public function use(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::UseCredentials->value);
    }

    private function canManage(AuthUser $authUser): bool
    {
        return $authUser instanceof User
               && $authUser->can(Permission::ManageCredentials->value);
    }
}
