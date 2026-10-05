<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Persists staff roles and syncs Spatie permissions from grouped Filament form state.
 * Only permission names defined in {@see Permission} may be synced.
 *
 * Spatie flushes the permission cache inside the transaction, before the commit: a request of the
 * same tenant in between would cache the old grants under the tenant's key until it expires. The
 * cache is flushed once more after the commit, so the new grants are what the next load reads.
 */
final class RoleWriterService
{
    /**
     * @param  string  $guard  Auth guard roles are scoped to (config `auth.defaults.guard`), bound in StaffServiceProvider.
     */
    public function __construct(
        private readonly PermissionRegistrar $permissionRegistrar,
        private readonly string $guard = 'web',
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Role
    {
        $this->assertCanManageRoles($actor);

        $guard       = $this->guard;
        $permissions = $this->flattenPermissionGroups($data['permission_groups'] ?? []);

        $this->assertUniqueRoleName($data['name'], $guard, null);

        return DB::transaction(function () use ($data, $guard, $permissions): Role {
            $role = Role::query()->create([
                'name'         => $data['name'],
                'display_name' => $data['display_name'] ?? null,
                'guard_name'   => $guard,
                'is_system'    => false,
            ]);
            $role->syncPermissions($permissions);
            $this->forgetPermissionCacheAfterCommit();

            return $role;
        });
    }

    /**
     * @param  array<string, list<string>|null>  $groups
     *
     * @return list<string>
     */
    public function flattenPermissionGroups(array $groups): array
    {
        $allowed = array_flip(Permission::values());

        $out = [];
        foreach ($groups as $selected) {
            if (! is_array($selected)) {
                continue;
            }
            foreach ($selected as $name) {
                if (is_string($name) && isset($allowed[$name])) {
                    $out[] = $name;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Role $role, array $data): Role
    {
        $this->assertCanManageRoles($actor);

        $guard       = $this->guard;
        $permissions = $this->flattenPermissionGroups($data['permission_groups'] ?? []);

        $nextName = $role->is_system ? $role->name : $data['name'];
        $this->assertUniqueRoleName($nextName, $guard, $role->getKey());

        return DB::transaction(function () use ($role, $data, $permissions, $nextName): Role {
            $role->fill([
                'name'         => $nextName,
                'display_name' => $data['display_name'] ?? null,
            ]);
            $role->save();
            $role->syncPermissions($permissions);
            $this->forgetPermissionCacheAfterCommit();

            return $role->refresh();
        });
    }

    private function forgetPermissionCacheAfterCommit(): void
    {
        DB::afterCommit(function (): void {
            $this->permissionRegistrar->forgetCachedPermissions();
        });
    }

    private function assertCanManageRoles(User $actor): void
    {
        if ($actor->isAdmin() && $actor->can(Permission::ManageUsers->value)) {
            return;
        }

        throw ValidationException::withMessages([
            'role' => __('You are not allowed to manage roles.'),
        ]);
    }

    private function assertUniqueRoleName(string $name, string $guard, mixed $ignoreId): void
    {
        $query = Role::query()
            ->where('name', $name)
            ->where('guard_name', $guard);

        if (null !== $ignoreId) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => __('This role name is already taken.'),
            ]);
        }
    }
}
