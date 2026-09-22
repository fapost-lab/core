<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Channel management moved from `manage_assistants` to `manage_channels`. The system
 * `content_manager` role keeps channel access in tenants seeded before the change.
 * A schema without the role (not seeded yet) is left alone: the seeder grants it there.
 * Custom roles are deliberately not granted anything.
 */
return new class () extends Migration {
    private const string Role = 'content_manager';

    private const string Permission = 'manage_channels';

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
        $roleId = DB::table('roles')
            ->where('name', self::Role)
            ->where('guard_name', self::Guard)
            ->value('id');

        $permissionId = DB::table('permissions')
            ->where('name', self::Permission)
            ->where('guard_name', self::Guard)
            ->value('id');

        if (null === $roleId || null === $permissionId) {
            return;
        }

        DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->where('role_id', $roleId)
            ->delete();
    }
};
