<?php

declare(strict_types=1);

namespace App\Domains\Staff\Enums;

/**
 * Delivery channel for a staff notification.
 *
 * The `notify_staff` node may select one or more channels. Each maps to a
 * {@see \App\Domains\Staff\Notifications\StaffNotifierInterface} implementation
 * resolved from the {@see \App\Domains\Staff\Notifications\StaffNotifierRegistry}.
 * A channel with no registered notifier (or unavailable for a recipient) is
 * skipped gracefully — additional transports (messenger bots) can be added
 * later without touching the node.
 */
enum StaffNotifyChannel: string
{
    /** In-app Filament database notification (always available). */
    case InApp = 'in_app';

    /** Email to the staff member's address. */
    case Email = 'email';

    /**
     * Builder dropdown options: value → localized label (admin-UI locale).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::InApp->value => (string) __('builder.nodes.notify.channels.in_app'),
            self::Email->value => (string) __('builder.nodes.notify.channels.email'),
        ];
    }
}
