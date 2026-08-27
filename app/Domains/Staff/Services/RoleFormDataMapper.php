<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;

/**
 * Maps Spatie role permissions to/from grouped Filament form state.
 */
final class RoleFormDataMapper
{
    /**
     * @return array<string, list<string>>
     */
    public function permissionGroupsFromRole(Role $role): array
    {
        $names = $role->permissions()->pluck('name')->all();

        $grouped = [];
        foreach (Permission::grouped() as $group => $permissionStrings) {
            $grouped[$group] = array_values(array_intersect($permissionStrings, $names));
        }

        return $grouped;
    }
}
