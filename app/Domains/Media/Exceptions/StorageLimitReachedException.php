<?php

declare(strict_types=1);

namespace App\Domains\Media\Exceptions;

use RuntimeException;

/**
 * Thrown when new content does not fit into the tenant's stored-media limit: nothing was written.
 *
 * Carries the numbers, not a sentence; each surface (REST, Filament, transcript, flow) words the
 * refusal itself, see {@see \App\Domains\Media\Services\StorageLimitMessage}.
 */
final class StorageLimitReachedException extends RuntimeException
{
    public const string ERROR_KEY = 'storage_limit_reached';

    public function __construct(
        public readonly string $key,
        public readonly int $limit,
        public readonly int $used,
        public readonly int $incoming,
    ) {
        parent::__construct(sprintf(
            'Media storage limit reached: %d of %d bytes used, %d more needed.',
            $used,
            $limit,
            $incoming,
        ));
    }
}
