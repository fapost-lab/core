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
     * upsert so cleanup heuristics can detect stale entries. `providerKind` is what the provider said it stored
     * (null when it did not say, or for a ref minted before the kind was kept). Required so a caller cannot clear a
     * stored kind by omission. The value is stored as given: an unknown kind still marks the ref as minted
     * by an uploader that reports kinds, so it stays cached; sending validates it.
     */
    public function upsert(
        string $blobId,
        string $channelId,
        string $providerFileId,
        ?CarbonImmutable $expiresAt,
        ?string $providerKind,
    ): MediaChannelRef;
}
