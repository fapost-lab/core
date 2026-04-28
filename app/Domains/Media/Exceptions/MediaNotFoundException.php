<?php

declare(strict_types=1);

namespace App\Domains\Media\Exceptions;

use RuntimeException;

/**
 * Thrown when a media file is requested by id but does not exist (or is soft-deleted
 * in a context that does not allow trashed lookups).
 */
final class MediaNotFoundException extends RuntimeException
{
    public static function forId(string $mediaFileId): self
    {
        return new self(sprintf('Media file [%s] was not found.', $mediaFileId));
    }

    public static function blobForId(string $blobId): self
    {
        return new self(sprintf('Media blob [%s] was not found.', $blobId));
    }
}
