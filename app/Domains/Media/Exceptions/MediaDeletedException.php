<?php

declare(strict_types=1);

namespace App\Domains\Media\Exceptions;

use RuntimeException;

/**
 * Thrown when an attempt is made to use a media file that has been soft-deleted.
 *
 * Engine catches this and routes the send_message node through its fallback branch.
 */
final class MediaDeletedException extends RuntimeException
{
    public static function forId(string $mediaFileId): self
    {
        return new self(sprintf('Media file [%s] is soft-deleted.', $mediaFileId));
    }
}
