<?php

declare(strict_types=1);

namespace App\Domains\Media\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when ingesting a media file from an inbound channel fails (download error,
 * storage write failure, etc.).
 */
final class MediaIngestException extends RuntimeException
{
    public static function downloadFailed(string $providerFileId, ?Throwable $previous = null): self
    {
        return new self(sprintf('Failed to download media [%s] from channel.', $providerFileId), 0, $previous);
    }
}
