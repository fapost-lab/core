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
                'manage_api_tokens',
                'manage_automations',
                'manage_credentials',
                'manage_roles',
                'publish_flow',
                'reply_conversations',
                'rotate_channel_token',
                'start_flow_runs',
                'use_credentials',
                'view_activity_log',
                'view_conversations',
                'view_operator_screens',
                'view_run_payloads',
            ],
            $values,
        );
    }

    public function test_every_permission_belongs_to_a_group(): void
    {
        $allowedGroups = ['assistants', 'users', 'content', 'contacts', 'conversations', 'analytics', 'system', 'automations'];

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

        $this->assertCount(8, $groups);
        $this->assertArrayHasKey('assistants', $groups);
        $this->assertArrayHasKey('users', $groups);
        $this->assertArrayHasKey('content', $groups);
        $this->assertArrayHasKey('contacts', $groups);
        $this->assertArrayHasKey('conversations', $groups);
        $this->assertArrayHasKey('analytics', $groups);
        $this->assertArrayHasKey('system', $groups);
        $this->assertArrayHasKey('automations', $groups);
        $this->assertSame(
            [Permission::ManageAutomations, Permission::ManageCredentials, Permission::UseCredentials, Permission::ViewRunPayloads, Permission::StartFlowRuns],
            $groups['automations'],
        );
    }

    public function test_a_token_never_holds_the_permissions_that_manage_people_tokens_or_the_installation(): void
    {
        $forbidden = array_map(
            static fn (Permission $p): string => $p->value,
            array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => $p->isForbiddenToTokens())),
        );

        sort($forbidden);
        $this->assertSame(['manage_api_tokens', 'manage_roles', 'manage_users', 'view_operator_screens'], $forbidden);
    }

    public function test_only_tenant_wide_permissions_are_not_narrowed_for_non_admins(): void
    {
        $wide = array_map(
            static fn (Permission $p): string => $p->value,
            array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => ! $p->isNarrowedByRecordPolicy())),
        );

        sort($wide);
        $this->assertSame(['manage_credentials', 'manage_settings', 'view_activity_log', 'view_system'], $wide);
        $this->assertTrue(Permission::StartFlowRuns->isNarrowedByRecordPolicy());
    }

    public function test_every_group_and_new_permission_has_a_description_in_each_language(): void
    {
        foreach (['en', 'ru', 'uk'] as $locale) {
            foreach (array_keys(Permission::groupedByGroup()) as $group) {
                $this->assertNotSame("staff.permission_groups.{$group}", __("staff.permission_groups.{$group}", [], $locale));
            }

            foreach ([Permission::ManageAutomations, Permission::ManageCredentials, Permission::UseCredentials, Permission::ViewRunPayloads, Permission::ManageApiTokens, Permission::StartFlowRuns] as $permission) {
                $this->assertNotSame("staff.permissions.labels.{$permission->value}", __("staff.permissions.labels.{$permission->value}", [], $locale));
                $this->assertNotSame("staff.permissions.descriptions.{$permission->value}", __("staff.permissions.descriptions.{$permission->value}", [], $locale));
            }
        }
    }

    public function test_content_manager_builds_automations_but_does_not_manage_credentials(): void
    {
        $perms = RoleEnum::ContentManager->permissions();

        $this->assertContains(Permission::ManageAutomations, $perms);
        $this->assertContains(Permission::UseCredentials, $perms);
        $this->assertContains(Permission::ViewRunPayloads, $perms);
        $this->assertNotContains(Permission::ManageCredentials, $perms);
    }

    public function test_analyst_gets_no_automation_or_credential_permission(): void
    {
        $perms = RoleEnum::Analyst->permissions();

        foreach ([Permission::ManageAutomations, Permission::ManageCredentials, Permission::UseCredentials, Permission::ViewRunPayloads, Permission::ManageApiTokens, Permission::StartFlowRuns] as $permission) {
            $this->assertNotContains($permission, $perms);
        }
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
