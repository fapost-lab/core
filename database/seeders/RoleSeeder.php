<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\Permission as PermissionModel;
use App\Domains\Staff\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Idempotent seed of all platform permissions and system roles.
 *
 * To add a new system role: add a case to {@see RoleEnum} with priority() and permissions().
 * Do NOT modify this seeder.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        // 1. Ensure all platform permissions exist.
        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, $guard);
        }

        // 2. Create / sync system roles from RoleEnum.
        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::query()->firstOrCreate(
                ['name' => $roleEnum->value, 'guard_name' => $guard],
                ['priority' => $roleEnum->priority(), 'is_system' => true],
            );

            // Keep priority in sync in case it changes in the enum.
            if ((int) $role->priority !== $roleEnum->priority()) {
                $role->update(['priority' => $roleEnum->priority()]);
            }

            $role->syncPermissions(
                array_map(static fn (Permission $p): string => $p->value, $roleEnum->permissions()),
            );
        }
    }
}
