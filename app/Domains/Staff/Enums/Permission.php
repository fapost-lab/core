<?php

declare(strict_types=1);

namespace App\Domains\Staff\Enums;

/**
 * Platform-level ACL permissions. Coarse-grained blocks (manage_X / view_X).
 * Single source of truth for the permission surface — used for seeding, policy checks, and UI.
 */
enum Permission: string
{
    case ManageBots      = 'manage_bots';
    case ManageUsers     = 'manage_users';
    case ManageFlow      = 'manage_flow';
    case ManageBroadcast = 'manage_broadcast';
    case ManageRag       = 'manage_rag';
    case ViewContacts    = 'view_contacts';
    case ManageContacts  = 'manage_contacts';
    case ViewAnalytics   = 'view_analytics';
    case ViewSystem      = 'view_system';
    case ManageSettings  = 'manage_settings';

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
     * Logical UI group for this permission (used in Filament form sections).
     */
    public function group(): string
    {
        return match ($this) {
            self::ManageBots  => 'bots',
            self::ManageUsers => 'users',
            self::ManageFlow, self::ManageBroadcast,
            self::ManageRag => 'content',
            self::ViewContacts, self::ManageContacts => 'contacts',
            self::ViewAnalytics => 'analytics',
            self::ViewSystem, self::ManageSettings => 'system',
        };
    }
}
