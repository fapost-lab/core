<?php

declare(strict_types=1);

namespace App\Domains\Media\Storage;

use DateTimeInterface;
use FAPost\Foundation\Media\Enums\MediaKind;
use Illuminate\Support\Str;

/**
 * Generates physical storage paths for media blobs.
 *
 * The path is intentionally opaque to the user — folders are a DB concept (media_folders),
 * not a filesystem layout. Keeping the path structure stable lets us migrate buckets
 * without touching user-visible URLs.
 */
final class StoragePathFactory
{
    /**
     * Build a deterministic per-blob path:
     * `tenants/{tenantId}/{kind}/{yyyy-mm}/{shard}/{blobId}.{ext}`.
     *
     * - {kind}    — MediaKind value derived from MIME type (image, video, audio, document…)
     * - {yyyy-mm} — monthly retention bucket from $createdAt
     * - {shard}   — 2 hex chars from md5($blobId), gives 256-way uniform distribution
     *
     * Same inputs always produce the same path (deterministic), allowing recomputation
     * from DB records without filesystem scans.
     */
    public function buildBlobPath(
        string $tenantId,
        string $blobId,
        string $mimeType,
        DateTimeInterface $createdAt,
        ?string $originalFilename = null,
    ): string {
        $kind      = MediaKind::fromMimeType($mimeType);
        $extension = $this->resolveExtension($mimeType, $originalFilename);
        $suffix    = '' === $extension ? '' : '.' . $extension;
        $yearMonth = $createdAt->format('Y-m');
        $shard     = mb_substr(md5($blobId), 0, 2);

        return sprintf('tenants/%s/%s/%s/%s/%s%s', $tenantId, $kind->value, $yearMonth, $shard, $blobId, $suffix);
    }

    private function resolveExtension(string $mimeType, ?string $originalFilename): string
    {
        if (null !== $originalFilename) {
            $ext = pathinfo($originalFilename, PATHINFO_EXTENSION);

            if (is_string($ext) && '' !== $ext) {
                return mb_strtolower(Str::ascii($ext));
            }
        }

        return match (mb_strtolower($mimeType)) {
            'image/jpeg'                                                              => 'jpg',
            'image/png'                                                               => 'png',
            'image/gif'                                                               => 'gif',
            'image/webp'                                                              => 'webp',
            'video/mp4'                                                               => 'mp4',
            'video/quicktime'                                                         => 'mov',
            'audio/mpeg'                                                              => 'mp3',
            'audio/ogg'                                                               => 'ogg',
            'audio/wav', 'audio/x-wav'                                                => 'wav',
            'application/pdf'                                                         => 'pdf',
            'application/zip'                                                         => 'zip',
            'application/json'                                                        => 'json',
            'application/msword'                                                      => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel'                                                => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
            'text/plain'                                                              => 'txt',
            'text/csv'                                                                => 'csv',
            default                                                                   => '',
        };
    }
}
