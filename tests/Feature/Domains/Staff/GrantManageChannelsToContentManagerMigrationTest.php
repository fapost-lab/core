<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Exercises database/migrations/tenant/2026_09_22_000001_grant_manage_channels_to_content_manager.php
 * directly. The harness runs it before any role is seeded, so that run is a no-op. Each
 * test builds the exact "before" state it needs with raw DB writes.
 */
final class GrantManageChannelsToContentManagerMigrationTest extends FeatureTestCase
{
    private const string ROLE = 'content_manager';

    private const string PERMISSION = 'manage_channels';

    private const string GUARD = 'web';

    public function test_up_attaches_the_permission_and_creates_it_when_absent(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->deletePermissionRow();
        $roleId = $this->insertRole(self::ROLE);

        $migration->up();

        $permissionId = $this->permissionId();
        $this->assertNotNull($permissionId, 'up() must create the manage_channels permission row when it is absent.');
        $this->assertTrue($this->isAttached($roleId, $permissionId));
    }

    public function test_up_is_idempotent(): void
    {
        $migration = $this->migration();
        $migration->down();

        $roleId = $this->insertRole(self::ROLE);

        $migration->up();
        $migration->up();

        $permissionId = $this->permissionId();
        $this->assertNotNull($permissionId);

        $count = DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->where('role_id', $roleId)
            ->count();

        $this->assertSame(1, $count, 'Running up() twice must not duplicate the pivot row.');
    }

    public function test_up_is_a_no_op_when_the_content_manager_role_does_not_exist(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->deletePermissionRow();
        DB::table('roles')->where('name', self::ROLE)->where('guard_name', self::GUARD)->delete();

        $migration->up();

        $this->assertNull($this->permissionId(), 'up() must not create the permission row when the role is missing.');
    }

    public function test_down_detaches_the_permission_without_deleting_the_rows(): void
    {
        $migration = $this->migration();
        $migration->down();

        $roleId = $this->insertRole(self::ROLE);
        $migration->up();

        $permissionId = $this->permissionId();
        $this->assertNotNull($permissionId);
        $this->assertTrue($this->isAttached($roleId, $permissionId));

        $migration->down();

        $this->assertFalse($this->isAttached($roleId, $permissionId));
        $this->assertNotNull(DB::table('roles')->where('id', $roleId)->value('id'), 'down() must not delete the role.');
        $this->assertNotNull($this->permissionId(), 'down() must not delete the permission.');
    }

    public function test_a_custom_role_holding_manage_assistants_is_left_untouched(): void
    {
        $migration = $this->migration();
        $migration->down();

        $this->deletePermissionRow();

        $this->insertRole(self::ROLE);
        $customRoleId       = $this->insertRole('custom_ops');
        $manageAssistantsId = $this->insertPermission('manage_assistants');
        DB::table('role_has_permissions')->insert([
            'permission_id' => $manageAssistantsId,
            'role_id'       => $customRoleId,
        ]);

        $migration->up();

        $permissionId = $this->permissionId();
        $this->assertNotNull($permissionId, 'up() still creates the permission row for content_manager elsewhere.');
        $this->assertFalse(
            $this->isAttached($customRoleId, $permissionId),
            'A custom role must not be granted manage_channels by this migration.',
        );
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/tenant/2026_09_22_000001_grant_manage_channels_to_content_manager.php');

        return $migration;
    }

    private function insertRole(string $name): string
    {
        $existing = DB::table('roles')->where('name', $name)->where('guard_name', self::GUARD)->value('id');

        if (null !== $existing) {
            return $existing;
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

    private function deletePermissionRow(): void
    {
        DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', self::GUARD)->delete();
    }

    private function permissionId(): ?string
    {
        return DB::table('permissions')->where('name', self::PERMISSION)->where('guard_name', self::GUARD)->value('id');
    }

    private function isAttached(string $roleId, string $permissionId): bool
    {
        return DB::table('role_has_permissions')
            ->where('permission_id', $permissionId)
            ->where('role_id', $roleId)
            ->exists();
    }
}
