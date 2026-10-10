<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Contracts\MediaBlobRepositoryInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\MediaUploadFailedException;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Storage\StoragePathFactory;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Media\Enums\MediaKind;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Persists incoming files into per-tenant storage with content-hash deduplication.
 *
 * The upload pipeline streams the bytes once into a local buffer, computing SHA-256 and the
 * byte count on the same pass. Content the tenant already stores is matched by hash and writes
 * nothing. New content first passes the storage gate ({@see MediaStorageGate}), then is written
 * to a temp location and promoted to its final path; the blob row is created (or matched to
 * one a racing upload just made) under the (tenant_id, content_hash) UNIQUE constraint.
 */
final class MediaUploader implements MediaUploaderInterface
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantMediaDisk $tenantMediaDisk,
        private readonly StoragePathFactory $pathFactory,
        private readonly MediaBlobRepositoryInterface $blobRepository,
        private readonly MediaStorageGate $storageGate,
    ) {
    }

    public function uploadFromUploadedFile(
        UploadedFile $file,
        ?MediaFolder $folder,
        string $name,
        MediaSource $source,
        ?string $uploadedBy = null,
    ): MediaFile {
        $stream = fopen($file->getRealPath(), 'rb');

        if (false === $stream) {
            throw MediaUploadFailedException::storageFailed('Cannot open uploaded file for reading.');
        }

        try {
            return $this->persistFromResource(
                resource: $stream,
                mimeType: $file->getMimeType() ?? 'application/octet-stream',
                originalFilename: $file->getClientOriginalName(),
                folder: $folder,
                name: $name,
                source: $source,
                uploadedBy: $uploadedBy,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function storeFromStream(
        StreamInterface $stream,
        string $mimeType,
        ?string $originalFilename,
        ?MediaFolder $folder,
        MediaSource $source,
        ?string $uploadedBy = null,
    ): MediaFile {
        $resource = $this->streamToResource($stream);

        try {
            return $this->persistFromResource(
                resource: $resource,
                mimeType: $mimeType,
                originalFilename: $originalFilename,
                folder: $folder,
                name: $originalFilename ?? sprintf('media-%s', Str::random(8)),
                source: $source,
                uploadedBy: $uploadedBy,
            );
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * @param  resource  $resource
     *
     * @throws StorageLimitReachedException when the content is new to the tenant and does not fit its storage limit
     */
    private function persistFromResource(
        $resource,
        string $mimeType,
        ?string $originalFilename,
        ?MediaFolder $folder,
        string $name,
        MediaSource $source,
        ?string $uploadedBy,
    ): MediaFile {
        $tenant   = $this->tenantContext->get();
        $disk     = $this->tenantMediaDisk->resolve($tenant);
        $diskName = $this->tenantMediaDisk->diskName($tenant);

        $tempPath    = sprintf('tenants/%s/media/_tmp/%s', $tenant->getId(), Str::ulid()->toBase32());
        $hashContext = hash_init('sha256');

        $sink = fopen('php://temp', 'w+b');

        if (false === $sink) {
            throw MediaUploadFailedException::storageFailed('Cannot allocate temp buffer for hashing.');
        }

        try {
            while (! feof($resource)) {
                $chunk = fread($resource, 1024 * 256);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                hash_update($hashContext, $chunk);
                fwrite($sink, $chunk);
            }

            // The buffer position is the byte count; strlen() would become mb_strlen() under Pint's
            // mb_str_functions fixer, which counts characters, not bytes.
            $size = ftell($sink);

            if (false === $size) {
                throw MediaUploadFailedException::storageFailed('Cannot measure the uploaded content.');
            }

            $contentHash = hash_final($hashContext);
            $existing    = $this->blobRepository->findByContentHash($tenant->getId(), $contentHash);

            if (null === $existing) {
                // Before the first byte reaches the tenant's disk: a refused upload leaves no trace.
                $this->storageGate->assertFits($size, $source);

                $blob = $this->storeNewBlob(
                    disk: $disk,
                    diskName: $diskName,
                    sink: $sink,
                    tempPath: $tempPath,
                    tenantId: $tenant->getId(),
                    contentHash: $contentHash,
                    size: $size,
                    mimeType: $mimeType,
                    originalFilename: $originalFilename,
                );
            } else {
                $blob = $existing;
            }
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        return $this->createMediaFile(
            blob: $blob,
            tenantId: $tenant->getId(),
            folder: $folder,
            name: $name,
            mimeType: $mimeType,
            source: $source,
            uploadedBy: $uploadedBy,
        );
    }

    /**
     * Writes the buffered content to the tenant's disk through a temp path and registers its blob.
     *
     * @param  resource  $sink  the buffered content
     */
    private function storeNewBlob(
        Filesystem $disk,
        string $diskName,
        $sink,
        string $tempPath,
        string $tenantId,
        string $contentHash,
        int $size,
        string $mimeType,
        ?string $originalFilename,
    ): MediaBlob {
        try {
            rewind($sink);
            $disk->writeStream($tempPath, $sink);
        } catch (Throwable $exception) {
            $disk->delete($tempPath);
            throw MediaUploadFailedException::storageFailed('Failed writing blob to tenant disk.', $exception);
        }

        $blobId    = mb_strtolower((string)Str::ulid()->toRfc4122());
        $finalPath = $this->pathFactory->buildBlobPath($tenantId, $blobId, $mimeType, now(), $originalFilename);

        try {
            $disk->move($tempPath, $finalPath);
        } catch (Throwable $exception) {
            $disk->delete($tempPath);
            throw MediaUploadFailedException::storageFailed('Failed promoting blob to final path.', $exception);
        }

        $blob = $this->blobRepository->createOrFindByContentHash(
            tenantId: $tenantId,
            contentHash: $contentHash,
            attributes: [
                'id'           => $blobId,
                'storage_path' => $finalPath,
                'storage_disk' => $diskName,
                'size'         => $size,
                'mime_type'    => $mimeType,
            ],
        );

        // Lost the race: the winner already promoted a blob; drop ours to avoid orphan.
        if ($blob->id !== $blobId) {
            $disk->delete($finalPath);
        }

        return $blob;
    }

    private function createMediaFile(
        MediaBlob $blob,
        string $tenantId,
        ?MediaFolder $folder,
        string $name,
        string $mimeType,
        MediaSource $source,
        ?string $uploadedBy,
    ): MediaFile {
        return MediaFile::query()->create([
            'tenant_id'   => $tenantId,
            'blob_id'     => $blob->id,
            'folder_id'   => $folder?->id,
            'name'        => $name,
            'kind'        => MediaKind::fromMimeType($mimeType),
            'metadata'    => [],
            'uploaded_by' => $uploadedBy,
            'source'      => $source,
        ]);
    }

    /**
     * @return resource
     */
    private function streamToResource(StreamInterface $stream)
    {
        $resource = fopen('php://temp', 'w+b');

        if (false === $resource) {
            throw MediaUploadFailedException::storageFailed('Cannot allocate temp resource for stream copy.');
        }

        $stream->rewind();

        while (! $stream->eof()) {
            $chunk = $stream->read(1024 * 256);

            if ('' === $chunk) {
                break;
            }

            fwrite($resource, $chunk);
        }

        rewind($resource);

        return $resource;
    }
}
