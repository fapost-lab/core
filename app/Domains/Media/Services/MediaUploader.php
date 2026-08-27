<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Contracts\MediaBlobRepositoryInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\MediaUploadFailedException;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Storage\StoragePathFactory;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Media\Enums\MediaKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Persists incoming files into per-tenant storage with content-hash deduplication.
 *
 * The upload pipeline streams the bytes once: SHA-256 is computed on the same pass that
 * writes to a temp location. After the hash is known the blob row is created (or matched
 * to an existing one) under the (tenant_id, content_hash) UNIQUE constraint, then the
 * temp file is either promoted to its final path or discarded if a duplicate already
 * occupies storage.
 */
final class MediaUploader implements MediaUploaderInterface
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantMediaDisk $tenantMediaDisk,
        private readonly StoragePathFactory $pathFactory,
        private readonly MediaBlobRepositoryInterface $blobRepository,
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
                size: $file->getSize() ?: null,
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
                size: null,
            );
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * @param  resource  $resource
     */
    private function persistFromResource(
        $resource,
        string $mimeType,
        ?string $originalFilename,
        ?MediaFolder $folder,
        string $name,
        MediaSource $source,
        ?string $uploadedBy,
        ?int $size,
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

        $bytesWritten = 0;

        try {
            while (! feof($resource)) {
                $chunk = fread($resource, 1024 * 256);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                hash_update($hashContext, $chunk);
                fwrite($sink, $chunk);
                $bytesWritten += mb_strlen($chunk);
            }

            rewind($sink);
            $disk->writeStream($tempPath, $sink);
        } catch (Throwable $exception) {
            $disk->delete($tempPath);
            throw MediaUploadFailedException::storageFailed('Failed writing blob to tenant disk.', $exception);
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        $contentHash = hash_final($hashContext);
        $size ??= $bytesWritten;

        $blobId    = mb_strtolower((string)Str::ulid()->toRfc4122());
        $finalPath = $this->pathFactory->buildBlobPath($tenant->getId(), $blobId, $mimeType, now(), $originalFilename);

        $existing = $this->blobRepository->findByContentHash($tenant->getId(), $contentHash);

        if (null !== $existing) {
            $disk->delete($tempPath);
            $blob = $existing;
        } else {
            try {
                $disk->move($tempPath, $finalPath);
            } catch (Throwable $exception) {
                $disk->delete($tempPath);
                throw MediaUploadFailedException::storageFailed('Failed promoting blob to final path.', $exception);
            }

            $blob = $this->blobRepository->createOrFindByContentHash(
                tenantId: $tenant->getId(),
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
