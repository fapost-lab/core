<?php

declare(strict_types=1);

namespace App\Domains\Media\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Thrown when storing a freshly uploaded blob fails (storage error, hash mismatch,
 * race condition that left the row inconsistent).
 */
final class MediaUploadFailedException extends RuntimeException
{
    public static function storageFailed(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Media upload failed: %s', $reason), 0, $previous);
    }
}
