<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the `rerun_flow` permission to tenants seeded before it existed, and gives it to the system roles as the seeder
 * does for a new tenant: `admin` (it holds every permission) and `content_manager` (it holds the automations group but
 * `manage_credentials`). Without the row a custom role could not be given it: the role form syncs permissions by name
 * and refuses an unknown one.
 *
 * A schema without either system role (not seeded yet) is left alone: the seeder does it there. Custom roles are
 * deliberately not granted anything.
 */
return new class () extends Migration {
    private const string Guard = 'web';

    private const string Permission = 'rerun_flow';

    /**
     * @var list<string>
     */
    private const array Roles = ['admin', 'content_manager'];

    public function up(): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('name', self::Roles)
            ->where('guard_name', self::Guard)
            ->pluck('id')
            ->all();

        if ([] === $roleIds) {
            return;
        }

        $permissionId = $this->permissionId();

        foreach ($roleIds as $roleId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id'       => (string) $roleId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', self::Permission)
            ->where('guard_name', self::Guard)
            ->value('id');

        if (null === $permissionId) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }

    /**
     * The permission's id, its row created when the tenant does not have it yet.
     */
    private function permissionId(): string
    {
        $id = DB::table('permissions')
            ->where('name', self::Permission)
            ->where('guard_name', self::Guard)
            ->value('id');

        if (null !== $id) {
            return (string) $id;
        }

        $id = mb_strtolower((string) Str::ulid()->toRfc4122());

        DB::table('permissions')->insert([
            'id'         => $id,
            'name'       => self::Permission,
            'guard_name' => self::Guard,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
};
