<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Tagging operation performed by the `set_tag` node.
 */
enum TagAction: string
{
    case Add    = 'add';
    case Remove = 'remove';
    case Toggle = 'toggle';

    /**
     * Builder dropdown options: value → localized label (admin-UI locale).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Add->value    => (string) __('builder.nodes.set_tag.actions.add'),
            self::Remove->value => (string) __('builder.nodes.set_tag.actions.remove'),
            self::Toggle->value => (string) __('builder.nodes.set_tag.actions.toggle'),
        ];
    }
}
