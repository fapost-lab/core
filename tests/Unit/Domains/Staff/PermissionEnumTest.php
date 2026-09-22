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
        $sensitive = array_filter(Permission::cases(), fn (Permission $p) => $p->isSensitive());
        $values    = array_map(fn (Permission $p) => $p->value, array_values($sensitive));

        sort($values);
        $this->assertSame(
            [
                'manage_roles',
                'publish_flow',
                'reply_conversations',
                'rotate_channel_token',
                'view_conversations',
            ],
            $values,
        );
    }

    public function test_every_permission_belongs_to_a_group(): void
    {
        $allowedGroups = ['assistants', 'users', 'content', 'contacts', 'conversations', 'analytics', 'system'];

        foreach (Permission::cases() as $permission) {
            $this->assertContains(
                $permission->group(),
                $allowedGroups,
                "Permission {$permission->value} has unexpected group '{$permission->group()}'.",
            );
        }
    }

    public function test_grouped_by_group_covers_every_ui_group(): void
    {
        $groups = Permission::groupedByGroup();

        $this->assertCount(7, $groups);
        $this->assertArrayHasKey('assistants', $groups);
        $this->assertArrayHasKey('users', $groups);
        $this->assertArrayHasKey('content', $groups);
        $this->assertArrayHasKey('contacts', $groups);
        $this->assertArrayHasKey('conversations', $groups);
        $this->assertArrayHasKey('analytics', $groups);
        $this->assertArrayHasKey('system', $groups);
    }

    public function test_values_covers_every_declared_case(): void
    {
        $this->assertCount(count(Permission::cases()), Permission::values());
        $this->assertSame(Permission::values(), array_unique(Permission::values()));
    }

    public function test_admin_role_contains_all_permissions(): void
    {
        $adminPerms = array_map(fn (Permission $p) => $p->value, RoleEnum::Admin->permissions());
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

    public function test_content_manager_has_manage_channels(): void
    {
        $this->assertContains(Permission::ManageChannels, RoleEnum::ContentManager->permissions());
    }

    public function test_analyst_has_view_flow_sessions_and_view_contacts(): void
    {
        $analystPerms = RoleEnum::Analyst->permissions();

        $this->assertContains(Permission::ViewFlowSessions, $analystPerms);
        $this->assertContains(Permission::ViewContacts, $analystPerms);
    }

    public function test_content_manager_can_work_the_inbox(): void
    {
        $perms = RoleEnum::ContentManager->permissions();

        $this->assertContains(Permission::ViewConversations, $perms);
        $this->assertContains(Permission::ReplyConversations, $perms);
    }

    public function test_analyst_cannot_read_transcripts(): void
    {
        // Analyst is an aggregate-numbers role. Message transcripts are the
        // most sensitive data we hold and must be granted deliberately.
        $perms = RoleEnum::Analyst->permissions();

        $this->assertNotContains(Permission::ViewConversations, $perms);
        $this->assertNotContains(Permission::ReplyConversations, $perms);
    }

    public function test_deprecated_manage_flow_is_not_in_non_admin_system_roles(): void
    {
        foreach (RoleEnum::cases() as $role) {
            if (RoleEnum::Admin === $role) {
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
