<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Media;

use Fapost\Foundation\Media\ChannelMediaLimitsInterface;
use Fapost\Foundation\Media\Enums\MediaKind;

/**
 * What the Telegram Bot API accepts when a bot sends a file: a photo up to 10 MB, a sticker up to 512 KB, any other
 * kind up to 50 MB.
 *
 * Source: https://core.telegram.org/bots/api#sending-files
 */
final readonly class TelegramMediaLimits implements ChannelMediaLimitsInterface
{
    private const int MEGABYTE = 1024 * 1024;

    public function channelType(): string
    {
        return 'telegram';
    }

    public function maxBytes(MediaKind $kind): ?int
    {
        return match ($kind) {
            MediaKind::Image   => 10 * self::MEGABYTE,
            MediaKind::Sticker => 512 * 1024,
            default            => 50 * self::MEGABYTE,
        };
    }
}
