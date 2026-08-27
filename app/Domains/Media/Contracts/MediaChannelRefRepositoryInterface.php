<?php

declare(strict_types=1);

namespace App\Domains\Media\Contracts;

use App\Domains\Media\Models\MediaChannelRef;
use Carbon\CarbonImmutable;

/**
 * Manages cached provider-side file ids for (blob, channel) pairs.
 */
interface MediaChannelRefRepositoryInterface
{
    public function find(string $blobId, string $channelId): ?MediaChannelRef;

    /**
     * Insert or refresh a (blob, channel) cache entry. Touches `uploaded_at` on every
     * upsert so cleanup heuristics can detect stale entries.
     */
    public function upsert(
        string $blobId,
        string $channelId,
        string $providerFileId,
        ?CarbonImmutable $expiresAt,
    ): MediaChannelRef;
}
