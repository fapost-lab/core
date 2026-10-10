<?php

declare(strict_types=1);

namespace App\Domains\Media\Exceptions;

use RuntimeException;

/**
 * A file that flows still reference cannot be deleted permanently: the flow would fail when it sends it.
 */
final class MediaFileInUseException extends RuntimeException
{
    public function __construct(
        public readonly string $fileId,
        public readonly int $references,
    ) {
        parent::__construct(sprintf('Media file %s has %d reference(s).', $fileId, $references));
    }
}
