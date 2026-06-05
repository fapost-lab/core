<?php

declare(strict_types=1);

namespace App\Domains\Staff\Enums;

/**
 * Recipient selection mode for the `notify_staff` node.
 */
enum StaffNotifyTarget: string
{
    /** All staff assigned to the current assistant (via user_assistants). */
    case Assistant = 'assistant';

    /** Staff holding a specific role. */
    case Role = 'role';

    /** An explicit list of staff user ids. */
    case Users = 'users';

    /**
     * Builder dropdown options: value → localized label (admin-UI locale).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Assistant->value => (string) __('builder.nodes.notify.targets.assistant'),
            self::Role->value      => (string) __('builder.nodes.notify.targets.role'),
            self::Users->value     => (string) __('builder.nodes.notify.targets.users'),
        ];
    }
}
