<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Exercises database/migrations/tenant/2026_11_20_000001_grant_automation_and_credential_permissions.php directly. The
 * harness runs it before any role is seeded, so that run is a no-op. Each test builds the "before" state it needs with
 * raw DB writes, after the migration's own `down()` removed what an earlier run left.
 */
final class GrantAutomationAndCredentialPermissionsMigrationTest extends FeatureTestCase
{
    private const string GUARD = 'web';

    private const array ALL = ['manage_automations', 'manage_credentials', 'use_credentials', 'view_run_payloads'];

    public function test_up_creates_the_permissions_and_grants_the_system_roles(): void
    {
        $migration = $this->migration();
        $migration->down();

        $admin   = $this->insertRole('admin');
        $manager = $this->insertRole('content_manager');

        $migration->up();

        foreach (self::ALL as $permission) {
            $this->assertNotNull($this->permissionId($permission), "up() must create {$permission}.");
        }

        $this->assertSame(self::ALL, $this->grantsOf($admin));
        $this->assertSame(['manage_automations', 'use_credentials', 'view_run_payloads'], $this->grantsOf($manager));
    }

    public function test_up_is_idempotent(): void
    {
        $migration = $this->migration();
        $migration->down();

        $admin = $this->insertRole('admin');

        $migration->up();
        $migration->up();

        $this->assertSame(4, DB::table('permissions')->whereIn('name', self::ALL)->count());
        $this->assertSame(4, DB::table('role_has_permissions')->where('role_id', $admin)->whereIn('permission_id', $this->permissionIds())->count());
    }

    public function test_a_custom_role_is_left_untouched(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->insertRole('admin');
        $custom   = $this->insertRole('custom_publisher');
        $publish  = $this->permissionId('publish_flow') ?? $this->insertPermission('publish_flow');
        $settings = $this->permissionId('manage_settings') ?? $this->insertPermission('manage_settings');
        DB::table('role_has_permissions')->insert([
            ['permission_id' => $publish, 'role_id' => $custom],
            ['permission_id' => $settings, 'role_id' => $custom],
        ]);

        $migration->up();

        $this->assertSame([], $this->grantsOf($custom));
    }

    public function test_up_without_the_system_roles_does_nothing(): void
    {
        $migration = $this->migration();
        $migration->down();

        DB::table('roles')->whereIn('name', ['admin', 'content_manager'])->where('guard_name', self::GUARD)->delete();

        $migration->up();

        $this->assertSame([], $this->permissionIds());
    }

    public function test_down_removes_the_grants_and_the_permissions(): void
    {
        $migration = $this->migration();
        $migration->down();

        $admin = $this->insertRole('admin');
        $migration->up();
        $migration->down();

        $this->assertSame([], $this->permissionIds());
        $this->assertSame([], $this->grantsOf($admin));
        $this->assertNotNull(DB::table('roles')->where('id', $admin)->value('id'), 'down() must not delete the role.');
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/tenant/2026_11_20_000001_grant_automation_and_credential_permissions.php');

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

    private function insertPermission(string $name): string
    {
        $id = mb_strtolower((string) Str::ulid()->toRfc4122());

        DB::table('permissions')->insert([
            'id'         => $id,
            'name'       => $name,
            'guard_name' => self::GUARD,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function permissionId(string $name): ?string
    {
        $id = DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

        return null === $id ? null : (string) $id;
    }

    /**
     * @return list<string>
     */
    private function permissionIds(): array
    {
        return DB::table('permissions')->whereIn('name', self::ALL)->where('guard_name', self::GUARD)->pluck('id')->map(strval(...))->all();
    }

    /**
     * The new permissions the role holds, by name, sorted.
     *
     * @return list<string>
     */
    private function grantsOf(string $roleId): array
    {
        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)
            ->whereIn('permissions.name', self::ALL)
            ->orderBy('permissions.name')
            ->pluck('permissions.name')
            ->all();
    }
}
