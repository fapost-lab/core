<?php

declare(strict_types=1);

namespace App\Domains\Media\Repositories;

use App\Domains\Media\Contracts\MediaChannelRefRepositoryInterface;
use App\Domains\Media\Models\MediaChannelRef;
use Carbon\CarbonImmutable;

/**
 * Eloquent implementation of MediaChannelRefRepositoryInterface backed by an
 * UNIQUE-constrained upsert on (blob_id, channel_id).
 */
final class EloquentMediaChannelRefRepository implements MediaChannelRefRepositoryInterface
{
    public function find(string $blobId, string $channelId): ?MediaChannelRef
    {
        return MediaChannelRef::query()
            ->where('blob_id', $blobId)
            ->where('channel_id', $channelId)
            ->first();
    }

    public function upsert(
        string $blobId,
        string $channelId,
        string $providerFileId,
        ?CarbonImmutable $expiresAt,
    ): MediaChannelRef {
        $now = CarbonImmutable::now();

        $existing = $this->find($blobId, $channelId);

        if (null !== $existing) {
            $existing->forceFill([
                'provider_file_id' => $providerFileId,
                'expires_at'       => $expiresAt,
                'uploaded_at'      => $now,
            ])->save();

            return $existing;
        }

        return MediaChannelRef::query()->create([
            'blob_id'          => $blobId,
            'channel_id'       => $channelId,
            'provider_file_id' => $providerFileId,
            'expires_at'       => $expiresAt,
            'uploaded_at'      => $now,
        ]);
    }
}
