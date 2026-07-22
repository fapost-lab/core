<?php

declare(strict_types=1);

namespace App\Domains\Media\Contracts;

use App\Domains\Channels\Models\Channel;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;

/**
 * Pulls a file from a channel provider and persists it as a tenant-owned MediaFile.
 *
 * Used by input nodes that accept user-supplied attachments. The provider's file id is
 * cached in media_channel_refs immediately so subsequent forwards skip a re-upload.
 */
interface MediaIngestorInterface
{
    /**
     * @throws \App\Domains\Media\Exceptions\ChannelMediaUploaderNotRegisteredException
     * @throws \App\Domains\Media\Exceptions\MediaIngestException
     */
    public function ingestFromChannel(
        Channel $channel,
        string $providerFileId,
        ?MediaFolder $folder = null,
        ?string $uploadedBy = null,
        MediaSource $source = MediaSource::InputNode,
    ): MediaFile;
}
