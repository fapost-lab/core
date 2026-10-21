<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Media\ChannelMediaLimitsInterface;
use Fapost\Foundation\Media\Enums\MediaKind;

/**
 * The fixture channel takes pictures up to 1 KB and sets no limit on anything else.
 */
final class EchoChannelMediaLimits implements ChannelMediaLimitsInterface
{
    public function channelType(): string
    {
        return 'echo';
    }

    public function maxBytes(MediaKind $kind): ?int
    {
        return MediaKind::Image === $kind ? 1024 : null;
    }
}
