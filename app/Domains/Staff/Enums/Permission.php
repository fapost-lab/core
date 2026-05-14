<?php

declare(strict_types=1);

namespace App\Domains\Staff\Enums;

/**
 * Platform-level ACL permissions. Granular blocks per domain action.
 * Single source of truth for the permission surface — used for seeding, policy checks, and UI.
 *
 * Sensitive permissions are flagged by {@see isSensitive()} and shown with a
 * warning badge in the Roles UI to discourage accidental assignment.
 */
enum Permission: string
{
    // ── Assistants group ─────────────────────────────────────────────────────
    case ManageAssistants        = 'manage_assistants';
    case ManageChannels          = 'manage_channels';
    case RotateChannelToken      = 'rotate_channel_token';
    case ManageAssistantSettings = 'manage_assistant_settings';

    // ── Flow / Content group ──────────────────────────────────────────────────
    /**
     * @deprecated Use granular flow permissions: ManageFlowDefinitions,
     *             PublishFlow, ViewFlowSessions, ManageFlowGroups,
     *             ManageTranslations.
     */
    case ManageFlow            = 'manage_flow';
    case ManageFlowDefinitions = 'manage_flow_definitions';
    case PublishFlow           = 'publish_flow';
    case ViewFlowSessions      = 'view_flow_sessions';
    case ManageFlowGroups      = 'manage_flow_groups';
    case ManageTranslations    = 'manage_translations';
    case ManageBroadcast       = 'manage_broadcast';
    case ManageRag             = 'manage_rag';
    case ViewMedia             = 'view_media';
    case ManageMedia           = 'manage_media';

    // ── Users / Roles group ───────────────────────────────────────────────────
    case ManageUsers = 'manage_users';
    case ManageRoles = 'manage_roles';

    // ── Contacts group ────────────────────────────────────────────────────────
    case ViewContacts   = 'view_contacts';
    case ManageContacts = 'manage_contacts';

    // ── Analytics / System group ──────────────────────────────────────────────
    case ViewAnalytics  = 'view_analytics';
    case ViewSystem     = 'view_system';
    case ManageSettings = 'manage_settings';

    /**
     * Enum cases grouped by their logical UI group.
     *
     * @return array<string, list<self>>
     */
    public static function groupedByGroup(): array
    {
        $groups = [];
        foreach (self::cases() as $case) {
            $groups[$case->group()][] = $case;
        }

        return $groups;
    }

    /**
     * Flat grouped permission strings (group → [value, ...]).
     *
     * @return array<string, list<string>>
     */
    public static function grouped(): array
    {
        $groups = [];
        foreach (self::cases() as $case) {
            $groups[$case->group()][] = $case->value;
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    /**
     * Human-readable label for this permission (translated via lang file).
     */
    public function label(): string
    {
        return __('staff.permissions.labels.' . $this->value);
    }

    /**
     * Optional longer description shown as tooltip in the Roles UI.
     * Empty string when no specific description is provided.
     */
    public function description(): string
    {
        $key        = 'staff.permissions.descriptions.' . $this->value;
        $translated = __($key);

        // Return empty string when no description is defined (key returned as-is).
        return $translated === $key ? '' : $translated;
    }

    /**
     * Whether this permission has elevated risk if assigned carelessly.
     * Sensitive permissions are shown with a warning badge in the Roles UI.
     */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::RotateChannelToken,
            self::PublishFlow,
            self::ManageRoles => true,
            default           => false,
        };
    }

    /**
     * Logical UI group for this permission (used in Filament form sections).
     */
    public function group(): string
    {
        return match ($this) {
            self::ManageAssistants,
            self::ManageChannels,
            self::RotateChannelToken,
            self::ManageAssistantSettings => 'assistants',

            self::ManageUsers,
            self::ManageRoles => 'users',

            self::ManageFlow,
            self::ManageFlowDefinitions,
            self::PublishFlow,
            self::ViewFlowSessions,
            self::ManageFlowGroups,
            self::ManageTranslations,
            self::ManageBroadcast,
            self::ManageRag,
            self::ViewMedia,
            self::ManageMedia => 'content',

            self::ViewContacts,
            self::ManageContacts => 'contacts',

            self::ViewAnalytics => 'analytics',

            self::ViewSystem,
            self::ManageSettings => 'system',
        };
    }
}
