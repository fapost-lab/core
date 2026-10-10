<?php

declare(strict_types=1);

namespace App\Domains\Staff\Policies;

use App\Domains\Staff\Enums\Permission;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Who reads the tenant's support access log: whoever manages its staff users. The log records the platform support
 * user's sessions, and that user is listed among the staff, so the right that shows the one shows the other.
 * Read only: the entries are written by Core, never by a person.
 */
final class SupportAccessEntryPolicy
{
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can(Permission::ManageUsers->value);
    }
}
