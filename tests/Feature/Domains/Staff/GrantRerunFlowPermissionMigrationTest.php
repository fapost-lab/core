<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Exercises database/migrations/tenant/2026_11_23_000002_grant_rerun_flow_permission.php directly. The harness runs it
 * before any role is seeded, so that run is a no-op. Each test builds the "before" state it needs with raw DB writes,
 * after the migration's own `down()` removed what an earlier run left.
 */
final class GrantRerunFlowPermissionMigrationTest extends FeatureTestCase
{
    private const string GUARD = 'web';

    private const string PERMISSION = 'rerun_flow';

    public function test_up_creates_the_permission_and_grants_the_system_roles(): void
    {
        $migration = $this->migration();
        $migration->down();

        $admin   = $this->insertRole('admin');
        $manager = $this->insertRole('content_manager');
        $analyst = $this->insertRole('analyst');

        $migration->up();

        $this->assertNotNull($this->permissionId());
        $this->assertTrue($this->holds($admin));
        $this->assertTrue($this->holds($manager));
        $this->assertFalse($this->holds($analyst));
    }

    public function test_up_is_idempotent(): void
    {
        $migration = $this->migration();
        $migration->down();

        $admin = $this->insertRole('admin');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('permissions')->where('name', self::PERMISSION)->count());
        $this->assertSame(1, DB::table('role_has_permissions')->where('role_id', $admin)->where('permission_id', $this->permissionId())->count());
    }

    public function test_a_custom_role_is_left_untouched(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->insertRole('admin');
        $custom = $this->insertRole('custom_operator');

        $migration->up();

        $this->assertFalse($this->holds($custom));
    }

    public function test_up_without_the_system_roles_does_nothing(): void
    {
        $migration = $this->migration();
        $migration->down();

        DB::table('roles')->whereIn('name', ['admin', 'content_manager'])->where('guard_name', self::GUARD)->delete();

        $migration->up();

        $this->assertNull($this->permissionId());
    }

    public function test_down_removes_the_grants_and_the_permission(): void
    {
        $migration = $this->migration();
        $migration->down();

        $admin = $this->insertRole('admin');
        $migration->up();
        $migration->down();

        $this->assertNull($this->permissionId());
        $this->assertFalse($this->holds($admin));
        $this->assertNotNull(DB::table('roles')->where('id', $admin)->value('id'), 'down() must not delete the role.');
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/tenant/2026_11_23_000002_grant_rerun_flow_permission.php');

        return $migration;
    }

    private function insertRole(string $name): string
    {
        $existing = DB::table('roles')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

        if (null !== $existing) {
            return (string) $existing;
        }

        $id = mb_strtolower((string) Str::ulid()->toRfc4122());

        DB::table('roles')->insert([
            'id'         => $id,
            'name'       => $name,
            'guard_name' => self::GUARD,
            'is_system'  => false,
            'priority'   => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function permissionId(): ?string
    {
        $id = DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', self::GUARD)->value('id');

        return null === $id ? null : (string) $id;
    }

    private function holds(string $roleId): bool
    {
        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)
            ->where('permissions.name', self::PERMISSION)
            ->exists();
    }
}
