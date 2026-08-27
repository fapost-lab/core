<?php

declare(strict_types=1);

namespace App\Domains\Channels\Enums;

/**
 * Static allow-list of published channel integrations.
 */
enum ChannelTypeEnum: string
{
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';

    /**
     * Build translated options for channel-management UI controls.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = __($case->labelKey());
        }

        return $options;
    }

    /**
     * Return the translation key used to present the channel in UI surfaces.
     */
    public function labelKey(): string
    {
        return match ($this) {
            self::Telegram => 'staff.channels.types.telegram',
            self::WhatsApp => 'staff.channels.types.whatsapp',
        };
    }
}
