<?php

declare(strict_types=1);

namespace App\Domains\Media\Exceptions;

use RuntimeException;

/**
 * Thrown when the dispatcher needs an uploader for a channel type but none is registered.
 *
 * Indicates a missing channel adapter (e.g. WhatsApp media adapter not yet implemented
 * while a tenant tries to send media through a WhatsApp channel).
 */
final class ChannelMediaUploaderNotRegisteredException extends RuntimeException
{
    public static function forType(string $channelType): self
    {
        return new self(sprintf('No media uploader registered for channel type [%s].', $channelType));
    }

    public static function downloaderForType(string $channelType): self
    {
        return new self(sprintf('No media downloader registered for channel type [%s].', $channelType));
    }
}
