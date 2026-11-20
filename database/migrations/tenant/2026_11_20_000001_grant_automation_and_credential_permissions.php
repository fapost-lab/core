<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the automation and credential permissions to tenants seeded before they existed, and gives them to the system
 * roles as the seeder does for a new tenant: `admin` all four (it holds every permission), `content_manager` all but
 * `manage_credentials` (it builds automations on credentials an administrator created). Without the rows a custom role
 * could not be given them: the role form syncs permissions by name and refuses an unknown one.
 *
 * A schema without either system role (not seeded yet) is left alone: the seeder does it there. Custom roles are
 * deliberately not granted anything.
 */
return new class () extends Migration {
    private const string Guard = 'web';

    /**
     * @var array<string, list<string>> role name → permission names it is given
     */
    private const array Grants = [
        'admin'           => ['manage_automations', 'manage_credentials', 'use_credentials', 'view_run_payloads'],
        'content_manager' => ['manage_automations', 'use_credentials', 'view_run_payloads'],
    ];

    /**
     * @var list<string>
     */
    private const array Permissions = ['manage_automations', 'manage_credentials', 'use_credentials', 'view_run_payloads'];

    public function up(): void
    {
        $roleIds = [];

        foreach (array_keys(self::Grants) as $role) {
            $roleId = DB::table('roles')
                ->where('name', $role)
                ->where('guard_name', self::Guard)
                ->value('id');

            if (null !== $roleId) {
                $roleIds[$role] = (string) $roleId;
            }
        }

        if ([] === $roleIds) {
            return;
        }

        $permissionIds = [];

        foreach (self::Permissions as $permission) {
            $permissionIds[$permission] = $this->permissionId($permission);
        }

        foreach ($roleIds as $role => $roleId) {
            foreach (self::Grants[$role] as $permission) {
                DB::table('role_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionIds[$permission],
                    'role_id'       => $roleId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::Permissions)
            ->where('guard_name', self::Guard)
            ->pluck('id')
            ->all();

        if ([] === $permissionIds) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    /**
     * The permission's id, its row created when the tenant does not have it yet.
     */
    private function permissionId(string $name): string
    {
        $id = DB::table('permissions')
            ->where('name', $name)
            ->where('guard_name', self::Guard)
            ->value('id');

        if (null !== $id) {
            return (string) $id;
        }

        $id = mb_strtolower((string) Str::ulid()->toRfc4122());

        DB::table('permissions')->insert([
            'id'         => $id,
            'name'       => $name,
            'guard_name' => self::Guard,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
