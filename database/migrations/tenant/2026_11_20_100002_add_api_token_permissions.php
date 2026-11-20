<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the `manage_api_tokens` and `start_flow_runs` permissions to tenants seeded before they existed, and gives them
 * to the system `admin` role as the seeder does for a new tenant (the admin holds every permission). No other role is
 * given them (owner's decision, 2026-11-20): administrators issue tokens until someone grants a custom role the right.
 * Without the rows a custom role could not be given them: the role form syncs permissions by name.
 *
 * A schema without the admin role (not seeded yet) is left alone: the seeder does it there.
 */
return new class () extends Migration {
    private const string Role = 'admin';

    private const string Guard = 'web';

    /**
     * @var list<string>
     */
    private const array Permissions = ['manage_api_tokens', 'start_flow_runs'];

    public function up(): void
    {
        $roleId = DB::table('roles')
            ->where('name', self::Role)
            ->where('guard_name', self::Guard)
            ->value('id');

        if (null === $roleId) {
            return;
        }

        foreach (self::Permissions as $permission) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $this->permissionId($permission),
                'role_id'       => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::Permissions as $permission) {
            $permissionId = DB::table('permissions')
                ->where('name', $permission)
                ->where('guard_name', self::Guard)
                ->value('id');

            if (null === $permissionId) {
                continue;
            }

            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }

    private function permissionId(string $permission): string
    {
        $permissionId = DB::table('permissions')
            ->where('name', $permission)
            ->where('guard_name', self::Guard)
            ->value('id');

        if (null !== $permissionId) {
            return (string) $permissionId;
        }

        $permissionId = mb_strtolower((string) Str::ulid()->toRfc4122());

        DB::table('permissions')->insert([
            'id'         => $permissionId,
            'name'       => $permission,
            'guard_name' => self::Guard,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $permissionId;
    }
};
