<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Recipient selection for the `notify` node's Contacts mode.
 *
 * Only tag-based and "all contacts of the assistant" selection exist today;
 * groups/segments arrive with the Broadcasting feature.
 */
enum ContactNotifyTarget: string
{
    /** Contacts carrying any of the selected tags. */
    case Tag = 'tag';

    /** Every contact linked to the target assistant. */
    case All = 'all';

    /**
     * Builder dropdown options: value → localized label (admin-UI locale).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Tag->value => (string) __('builder.nodes.notify.contact_targets.tag'),
            self::All->value => (string) __('builder.nodes.notify.contact_targets.all'),
        ];
    }
}
