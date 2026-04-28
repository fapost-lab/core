<?php

declare(strict_types=1);

namespace App\Domains\Media\Contracts;

use App\Domains\Media\Models\MediaBlob;

/**
 * Persists media_blobs with race-safe deduplication on (tenant_id, content_hash).
 */
interface MediaBlobRepositoryInterface
{
    public function findByContentHash(string $tenantId, string $contentHash): ?MediaBlob;

    /**
     * Insert a new blob row, or return the existing one if a concurrent writer already
     * created it for the same (tenant_id, content_hash). Implementations rely on the
     * UNIQUE constraint to detect the race.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOrFindByContentHash(string $tenantId, string $contentHash, array $attributes): MediaBlob;
}
