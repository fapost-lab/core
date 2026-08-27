<?php

declare(strict_types=1);

namespace App\Domains\Media\Contracts;

use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use Illuminate\Support\Collection;

/**
 * Tenant-facing CRUD operations on media files and folders.
 *
 * All write operations run inside the active tenant context — callers must ensure
 * tenant resolution before invoking.
 */
interface MediaServiceInterface
{
    /**
     * Find a media file by id within the active tenant. Returns trashed entries.
     */
    public function find(string $mediaFileId): ?MediaFile;

    /**
     * Build a signed, time-limited URL that streams the file's bytes through the
     * `media.files.raw` route. Returns null for soft-deleted files.
     */
    public function signedUrl(MediaFile $file): ?string;

    /**
     * Resolve (or lazily create) the per-tenant "Inbox" folder used as the default
     * destination for files ingested by input nodes.
     */
    public function findOrCreateInboxFolder(): MediaFolder;

    public function rename(MediaFile $file, string $newName): MediaFile;

    public function move(MediaFile $file, ?MediaFolder $targetFolder): MediaFile;

    public function softDelete(MediaFile $file): void;

    public function restore(MediaFile $file): MediaFile;

    public function createFolder(string $name, ?MediaFolder $parent, ?string $createdBy = null): MediaFolder;

    public function renameFolder(MediaFolder $folder, string $newName): MediaFolder;

    public function moveFolder(MediaFolder $folder, ?MediaFolder $newParent): MediaFolder;

    public function deleteFolder(MediaFolder $folder): void;

    /**
     * @return Collection<int, MediaFile>
     */
    public function listFolderContents(?MediaFolder $folder): Collection;
}
