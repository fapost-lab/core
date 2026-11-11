<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domains\Staff\Enums\Permission;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Who reads the tenant's audit log: whoever holds `view_activity_log` (administrators, through `Gate::before`). It
 * shows what each colleague did, so it is its own sensitive permission and not part of `view_system`. Read only:
 * entries are written by Core, never by a person.
 *
 * Here and not in the Audit domain, which knows no Staff permission.
 */
final class ActivityEntryPolicy
{
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can(Permission::ViewActivityLog->value);
    }
}
