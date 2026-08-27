<?php

declare(strict_types=1);

namespace App\Domains\Media\Contracts;

use App\Domains\Channels\Models\Channel;
use App\Domains\Media\DTO\DispatchResult;
use App\Domains\Media\Models\MediaFile;
use FAPost\Foundation\Media\DTO\UploadContext;

/**
 * Resolves the provider-side file id for sending a media file through a channel.
 *
 * Lazy-uploads on first send, caches the result per (blob, channel) and re-uploads when
 * the cached `expires_at` lapses. Idempotent: repeated calls for the same (file, channel)
 * never trigger a second upload while the cache is fresh.
 *
 * The returned {@see DispatchResult} carries `alreadyDelivered` so handlers can detect
 * upload-as-send providers (Telegram) and avoid double-sending.
 */
interface MediaDispatcherInterface
{
    /**
     * @throws \App\Domains\Media\Exceptions\ChannelMediaUploaderNotRegisteredException
     * @throws \App\Domains\Media\Exceptions\MediaDeletedException
     */
    public function ensureUploadedToChannel(
        MediaFile $media,
        Channel $channel,
        ?UploadContext $context = null,
    ): DispatchResult;
}
