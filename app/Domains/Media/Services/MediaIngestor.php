<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Media\Contracts\MediaChannelRefRepositoryInterface;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\MediaIngestException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Registries\ChannelMediaDownloaderRegistry;
use Throwable;

/**
 * Pulls a file from a channel provider and persists it as a tenant-owned MediaFile.
 *
 * Reuses the upload pipeline (and therefore the deduplication path) so an inbound copy
 * of an already-known blob does not re-upload to storage. The original provider_file_id
 * is cached so the same file can be forwarded back through the same channel without an
 * extra upload.
 */
final readonly class MediaIngestor implements MediaIngestorInterface
{
    public function __construct(
        private ChannelMediaDownloaderRegistry $downloaderRegistry,
        private MediaUploaderInterface $uploader,
        private MediaChannelRefRepositoryInterface $refs,
    ) {
    }

    public function ingestFromChannel(
        Channel $channel,
        string $providerFileId,
        ?MediaFolder $folder = null,
        ?string $uploadedBy = null,
        MediaSource $source = MediaSource::InputNode,
    ): MediaFile {
        $downloader = $this->downloaderRegistry->forChannelType($channel->type->value);

        try {
            $download = $downloader->download($channel, $providerFileId);
        } catch (Throwable $exception) {
            throw MediaIngestException::downloadFailed($providerFileId, $exception);
        }

        $media = $this->uploader->storeFromStream(
            stream: $download->stream,
            mimeType: $download->mimeType,
            originalFilename: $download->originalFilename,
            folder: $folder,
            source: $source,
            uploadedBy: $uploadedBy,
        );

        $this->refs->upsert(
            blobId: $media->blob_id,
            channelId: $channel->id,
            providerFileId: $providerFileId,
            expiresAt: $download->expiresAt,
        );

        return $media;
    }
}
