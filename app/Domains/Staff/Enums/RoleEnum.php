<?php

declare(strict_types=1);

namespace App\Domains\Staff\Enums;

/**
 * System roles for the staff domain. Single source of truth for role names,
 * priorities and their permission sets. Seeder iterates cases() — never duplicated.
 *
 * To add a new system role: add a case here with priority() and permissions() entries.
 * Do NOT touch RoleSeeder.
 */
enum RoleEnum: string
{
    case Admin          = 'admin';
    case ContentManager = 'content_manager';
    case Analyst        = 'analyst';

    /**
     * Numeric priority. Higher number = more access.
     * Custom roles created through UI must have priority strictly below the creator's.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Admin          => 100,
            self::ContentManager => 50,
            self::Analyst        => 30,
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),

            self::ContentManager => [
                Permission::ManageAssistants,
                Permission::ManageAssistantSettings,
                // Flow / content
                Permission::ManageFlowDefinitions,
                Permission::PublishFlow,
                Permission::ViewFlowSessions,
                Permission::ManageFlowGroups,
                Permission::ManageTranslations,
                Permission::ManageBroadcast,
                Permission::ManageRag,
                Permission::ManageMedia,
                Permission::ViewMedia,
                // Contacts
                Permission::ViewContacts,
                // Analytics
                Permission::ViewAnalytics,
            ],

            self::Analyst => [
                Permission::ViewAnalytics,
                Permission::ViewFlowSessions,
                Permission::ViewContacts,
            ],
        };
    }
}
