<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the `view_activity_log` permission to tenants seeded before it existed, and gives it to the system `admin` role
 * as the seeder does for a new tenant (the admin holds every permission). Without the row a custom role could not be
 * given the permission: the role form syncs permissions by name and refuses an unknown one.
 *
 * A schema without the permissions table or the admin role (not seeded yet) is left alone: the seeder does it there.
 */
return new class () extends Migration {
    private const string Role = 'admin';

    private const string Permission = 'view_activity_log';

    private const string Guard = 'web';

    public function up(): void
    {
        $roleId = DB::table('roles')
            ->where('name', self::Role)
            ->where('guard_name', self::Guard)
            ->value('id');

        if (null === $roleId) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('name', self::Permission)
            ->where('guard_name', self::Guard)
            ->value('id');

        if (null === $permissionId) {
            $permissionId = mb_strtolower((string) Str::ulid()->toRfc4122());

            DB::table('permissions')->insert([
                'id'         => $permissionId,
                'name'       => self::Permission,
                'guard_name' => self::Guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('role_has_permissions')->insertOrIgnore([
            'permission_id' => $permissionId,
            'role_id'       => $roleId,
        ]);
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
};
