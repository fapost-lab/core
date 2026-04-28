<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Tenant-facing CRUD over media files and folders.
 *
 * Folder rename/move maintains `path_cache` for the folder and all descendants in a
 * single transaction so listings stay consistent without re-walking the parent chain.
 */
final readonly class MediaService implements MediaServiceInterface
{
    private const string INBOX_FOLDER_NAME = 'Inbox';

    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function find(string $mediaFileId): ?MediaFile
    {
        $tenant = $this->tenantContext->get();

        return MediaFile::query()
            ->withTrashed()
            ->where('tenant_id', $tenant->getId())
            ->whereKey($mediaFileId)
            ->first();
    }

    public function signedUrl(MediaFile $file): ?string
    {
        if (null !== $file->deleted_at) {
            return null;
        }

        return URL::signedRoute(
            'media.files.raw',
            ['file' => $file->id],
            now()->addSeconds((int)config('media.download_url_ttl_seconds', 300)),
        );
    }

    public function findOrCreateInboxFolder(): MediaFolder
    {
        $tenant = $this->tenantContext->get();

        $folder = MediaFolder::query()
            ->where('tenant_id', $tenant->getId())
            ->whereNull('parent_id')
            ->where('name', self::INBOX_FOLDER_NAME)
            ->first();

        if (null !== $folder) {
            return $folder;
        }

        return $this->createFolder(self::INBOX_FOLDER_NAME, null, null);
    }

    public function createFolder(string $name, ?MediaFolder $parent, ?string $createdBy = null): MediaFolder
    {
        $tenant = $this->tenantContext->get();

        $pathCache = null === $parent
            ? '/' . $name
            : mb_rtrim($parent->path_cache, '/') . '/' . $name;

        return MediaFolder::query()->create([
            'tenant_id'  => $tenant->getId(),
            'parent_id'  => $parent?->id,
            'name'       => $name,
            'path_cache' => $pathCache,
            'created_by' => $createdBy,
        ]);
    }

    public function rename(MediaFile $file, string $newName): MediaFile
    {
        $file->forceFill(['name' => $newName])->save();

        return $file;
    }

    public function move(MediaFile $file, ?MediaFolder $targetFolder): MediaFile
    {
        $file->forceFill(['folder_id' => $targetFolder?->id])->save();

        return $file;
    }

    public function softDelete(MediaFile $file): void
    {
        $file->delete();
    }

    public function restore(MediaFile $file): MediaFile
    {
        $file->restore();

        return $file;
    }

    public function renameFolder(MediaFolder $folder, string $newName): MediaFolder
    {
        DB::transaction(function () use ($folder, $newName): void {
            $oldPath = $folder->path_cache;
            $newPath = $this->buildFolderPath($folder->parent_id, $newName);

            $folder->forceFill([
                'name'       => $newName,
                'path_cache' => $newPath,
            ])->save();

            $this->rewriteDescendantPaths($oldPath, $newPath);
        });

        return $folder->fresh() ?? $folder;
    }

    public function moveFolder(MediaFolder $folder, ?MediaFolder $newParent): MediaFolder
    {
        DB::transaction(function () use ($folder, $newParent): void {
            $oldPath = $folder->path_cache;
            $newPath = $this->buildFolderPath($newParent?->id, $folder->name);

            $folder->forceFill([
                'parent_id'  => $newParent?->id,
                'path_cache' => $newPath,
            ])->save();

            $this->rewriteDescendantPaths($oldPath, $newPath);
        });

        return $folder->fresh() ?? $folder;
    }

    public function deleteFolder(MediaFolder $folder): void
    {
        $folder->delete();
    }

    public function listFolderContents(?MediaFolder $folder): Collection
    {
        $tenant = $this->tenantContext->get();

        return MediaFile::query()
            ->where('tenant_id', $tenant->getId())
            ->where('folder_id', $folder?->id)
            ->orderBy('name')
            ->get();
    }

    private function buildFolderPath(?string $parentId, string $name): string
    {
        if (null === $parentId) {
            return '/' . $name;
        }

        $parent = MediaFolder::query()->findOrFail($parentId);

        return mb_rtrim($parent->path_cache, '/') . '/' . $name;
    }

    private function rewriteDescendantPaths(string $oldPath, string $newPath): void
    {
        $tenant = $this->tenantContext->get();

        MediaFolder::query()
            ->where('tenant_id', $tenant->getId())
            ->where('path_cache', 'like', $oldPath . '/%')
            ->each(function (MediaFolder $descendant) use ($oldPath, $newPath): void {
                $descendant->forceFill([
                    'path_cache' => $newPath . mb_substr($descendant->path_cache, mb_strlen($oldPath)),
                ])->save();
            });
    }
}
