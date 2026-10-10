<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Exceptions\StaffChangeRefusedException;
use App\Domains\Staff\Models\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff roles as the admin panel lists and removes them, and the permission catalogue its form shows. Creating and
 * changing a role goes through {@see RoleWriterService}.
 *
 * A system role is never deleted, whoever asks: an administrator passes the policy through `Gate::before`, so the
 * refusal lives here (and in the model's `deleting` hook as the last stop). After a delete the tenant's permission
 * cache is flushed, so no request keeps the removed role's grants.
 */
final readonly class StaffRoleService
{
    public function __construct(
        private PermissionRegistrar $permissionRegistrar,
    ) {
    }

    /**
     * @return Builder<Role>
     */
    public function query(): Builder
    {
        return Role::query()->withCount('permissions');
    }

    /**
     * A role of the current tenant; anything else, a malformed id included, is not found.
     */
    public function find(string $id): Role
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(Role::class, [$id]);
        }

        return Role::query()->whereKey($id)->firstOrFail();
    }

    /**
     * The role's permission names that the catalogue knows, in catalogue order.
     *
     * @return list<string>
     */
    public function permissionsOf(Role $role): array
    {
        $names = $role->permissions()->pluck('name')->all();

        return array_values(array_intersect(Permission::values(), $names));
    }

    /**
     * Every permission, grouped as the form shows them, with its label, description and whether it is sensitive.
     *
     * @return list<array{key: string, label: string, permissions: list<array{value: string, label: string, description: string, sensitive: bool}>}>
     */
    public function catalogue(): array
    {
        $groups = [];

        foreach (Permission::groupedByGroup() as $group => $cases) {
            $groups[] = [
                'key'         => $group,
                'label'       => __('staff.permission_groups.' . $group),
                'permissions' => array_map(static fn (Permission $permission): array => [
                    'value'       => $permission->value,
                    'label'       => $permission->label(),
                    'description' => $permission->description(),
                    'sensitive'   => $permission->isSensitive(),
                ], $cases),
            ];
        }

        return $groups;
    }

    /**
     * @throws StaffChangeRefusedException for a system role
     */
    public function delete(Role $role): void
    {
        if ($role->isSystemRole()) {
            throw new StaffChangeRefusedException(StaffChangeRefusedException::SYSTEM_ROLE);
        }

        $role->delete();
        $this->permissionRegistrar->forgetCachedPermissions();
    }

    /**
     * Deletes the listed roles one by one; system roles are skipped and counted. Unknown ids are ignored.
     *
     * @param  list<string>  $ids
     *
     * @return array{deleted: int, blocked: int}
     */
    public function deleteMany(array $ids): array
    {
        $ids    = array_values(array_filter($ids, static fn (string $id): bool => Str::isUuid($id)));
        $result = ['deleted' => 0, 'blocked' => 0];

        if ([] === $ids) {
            return $result;
        }

        foreach (Role::query()->whereKey($ids)->get() as $role) {
            try {
                $this->delete($role);
                ++$result['deleted'];
            } catch (StaffChangeRefusedException) {
                ++$result['blocked'];
            }
        }

        return $result;
    }
}
