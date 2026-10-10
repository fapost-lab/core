<?php

declare(strict_types=1);

namespace App\Domains\Media\Contracts;

use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use Illuminate\Http\UploadedFile;
use Psr\Http\Message\StreamInterface;

/**
 * Persists incoming files into the per-tenant media store with content-hash deduplication.
 */
interface MediaUploaderInterface
{
    /**
     * Store an HTTP-uploaded file (Filament/REST entrypoint).
     *
     * @throws StorageLimitReachedException when the content is new and does not fit the tenant's storage limit
     */
    public function uploadFromUploadedFile(
        UploadedFile $file,
        ?MediaFolder $folder,
        string $name,
        MediaSource $source,
        ?string $uploadedBy = null,
    ): MediaFile;

    /**
     * Store a stream coming from a non-HTTP source (input-node ingest, internal API).
     *
     * @throws StorageLimitReachedException when the content is new and does not fit the tenant's storage limit
     */
    public function storeFromStream(
        StreamInterface $stream,
        string $mimeType,
        ?string $originalFilename,
        ?MediaFolder $folder,
        MediaSource $source,
        ?string $uploadedBy = null,
    ): MediaFile;
}
