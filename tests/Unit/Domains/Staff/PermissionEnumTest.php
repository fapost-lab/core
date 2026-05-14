<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Staff;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use Tests\TestCase;

final class PermissionEnumTest extends TestCase
{
    public function test_all_permissions_have_non_empty_label(): void
    {
        foreach (Permission::cases() as $permission) {
            $label = $permission->label();
            $this->assertNotEmpty($label, "Permission {$permission->value} has no label translation.");
            $this->assertStringNotContainsString('staff.permissions.labels.', $label);
        }
    }

    public function test_sensitive_permissions_are_exactly_the_declared_set(): void
    {
        $sensitive = array_filter(Permission::cases(), fn(Permission $p) => $p->isSensitive());
        $values    = array_map(fn(Permission $p) => $p->value, array_values($sensitive));

        sort($values);
        $this->assertSame(
            ['manage_roles', 'publish_flow', 'rotate_channel_token'],
            $values,
        );
    }

    public function test_every_permission_belongs_to_a_group(): void
    {
        $allowedGroups = ['assistants', 'users', 'content', 'contacts', 'analytics', 'system'];

        foreach (Permission::cases() as $permission) {
            $this->assertContains(
                $permission->group(),
                $allowedGroups,
                "Permission {$permission->value} has unexpected group '{$permission->group()}'.",
            );
        }
    }

    public function test_grouped_by_group_returns_six_groups(): void
    {
        $groups = Permission::groupedByGroup();

        $this->assertCount(6, $groups);
        $this->assertArrayHasKey('assistants', $groups);
        $this->assertArrayHasKey('users', $groups);
        $this->assertArrayHasKey('content', $groups);
        $this->assertArrayHasKey('contacts', $groups);
        $this->assertArrayHasKey('analytics', $groups);
        $this->assertArrayHasKey('system', $groups);
    }

    public function test_values_returns_all_21_permissions(): void
    {
        $this->assertCount(21, Permission::values());
    }

    public function test_admin_role_contains_all_permissions(): void
    {
        $adminPerms = array_map(fn(Permission $p) => $p->value, RoleEnum::Admin->permissions());
        $allPerms   = Permission::values();

        sort($adminPerms);
        sort($allPerms);

        $this->assertSame($allPerms, $adminPerms);
    }

    public function test_content_manager_does_not_have_sensitive_channel_rotation(): void
    {
        $perms = RoleEnum::ContentManager->permissions();

        $this->assertNotContains(Permission::RotateChannelToken, $perms);
    }

    public function test_content_manager_has_publish_flow_explicitly(): void
    {
        $this->assertContains(Permission::PublishFlow, RoleEnum::ContentManager->permissions());
    }

    public function test_analyst_has_view_flow_sessions_and_view_contacts(): void
    {
        $analystPerms = RoleEnum::Analyst->permissions();

        $this->assertContains(Permission::ViewFlowSessions, $analystPerms);
        $this->assertContains(Permission::ViewContacts, $analystPerms);
    }

    public function test_deprecated_manage_flow_is_not_in_non_admin_system_roles(): void
    {
        foreach (RoleEnum::cases() as $role) {
            if ($role === RoleEnum::Admin) {
                continue; // Admin gets all cases including deprecated — expected.
            }

            $this->assertNotContains(
                Permission::ManageFlow,
                $role->permissions(),
                "Deprecated ManageFlow should not be in {$role->value} permissions.",
            );
        }
    }
}
