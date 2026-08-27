<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Audience mode for the `notify` node.
 *
 * Staff and contacts are fundamentally different recipients (admin users vs bot
 * end-users), with different delivery channels and content language, so the
 * node branches its whole config on this switch.
 */
enum NotifyMode: string
{
    /** Notify admin-panel staff users (in-app / email). */
    case Staff = 'staff';

    /** Notify bot contacts via an assistant (tag-targeted broadcast). */
    case Contacts = 'contacts';

    /**
     * Builder dropdown options: value → localized label (admin-UI locale).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Staff->value    => (string) __('builder.nodes.notify.modes.staff'),
            self::Contacts->value => (string) __('builder.nodes.notify.modes.contacts'),
        ];
    }
}
