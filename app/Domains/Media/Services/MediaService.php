<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Tenant-facing CRUD over media files and folders.
 *
 * Folder rename/move maintains `path_cache` for the folder and all descendants in a
 * single transaction so listings stay consistent without re-walking the parent chain.
 */
final readonly class MediaService implements MediaServiceInterface
{
    private const string INBOX_FOLDER_NAME = 'Inbox';

    /**
     * @param  int  $downloadUrlTtlSeconds  Signed-URL lifetime (config `media.download_url_ttl_seconds`), bound in MediaServiceProvider.
     */
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantMediaDisk $tenantMediaDisk,
        private int $downloadUrlTtlSeconds = 300,
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
            now()->addSeconds($this->downloadUrlTtlSeconds),
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

    public function forceDelete(MediaFile $file): void
    {
        $blobId = $file->blob_id;

        $file->forceDelete();

        if (MediaFile::query()->withTrashed()->where('blob_id', $blobId)->exists()) {
            return;
        }

        $blob = MediaBlob::query()->find($blobId);

        if (null === $blob) {
            return;
        }

        // The row goes first: its foreign key (restrict) refuses the delete when a parallel upload
        // of the same content just attached a new file, and then the object must stay. The delete runs
        // in its own (nested) transaction: PostgreSQL aborts the surrounding transaction on the
        // violation, and the savepoint keeps a caller's transaction usable after it.
        try {
            $blob->getConnection()->transaction(static fn (): ?bool => $blob->delete());
        } catch (QueryException $exception) {
            if ($this->isForeignKeyViolation($exception)) {
                return;
            }

            throw $exception;
        }

        // The file is already gone, so a failing disk must not turn the delete into an error. The
        // object is left behind and its path logged; the usage count no longer includes it.
        try {
            $this->tenantMediaDisk->resolve($this->tenantContext->get())->delete($blob->storage_path);
        } catch (Throwable $exception) {
            Log::warning('media.blob.object_delete_failed', [
                'blob_id' => $blob->id,
                'path'    => $blob->storage_path,
                'error'   => $exception->getMessage(),
            ]);
        }
    }

    public function renameFolder(MediaFolder $folder, string $newName): MediaFolder
    {
        DB::transaction(function () use ($folder, $newName): void {
            $newPath = $this->buildFolderPath($folder->parent_id, $newName);

            $folder->forceFill([
                'name'       => $newName,
                'path_cache' => $newPath,
            ])->save();

            $this->rewriteDescendantPaths($folder);
        });

        return $folder->fresh() ?? $folder;
    }

    public function moveFolder(MediaFolder $folder, ?MediaFolder $newParent): MediaFolder
    {
        DB::transaction(function () use ($folder, $newParent): void {
            $newPath = $this->buildFolderPath($newParent?->id, $folder->name);

            $folder->forceFill([
                'parent_id'  => $newParent?->id,
                'path_cache' => $newPath,
            ])->save();

            $this->rewriteDescendantPaths($folder);
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
            // Conversation-transcript attachments are ingested through the same
            // pipeline but must not flood the admin media library (spec §6.1/§14).
            ->where('source', '!=', MediaSource::Conversation->value)
            ->orderBy('name')
            ->get();
    }

    private function isForeignKeyViolation(QueryException $exception): bool
    {
        // 23503 is PostgreSQL's foreign_key_violation; SQLite reports a generic 23000 with this text.
        return '23503' === (string) $exception->getCode()
            || str_contains(mb_strtolower($exception->getMessage()), 'foreign key constraint failed');
    }

    private function buildFolderPath(?string $parentId, string $name): string
    {
        if (null === $parentId) {
            return '/' . $name;
        }

        $parent = MediaFolder::query()->findOrFail($parentId);

        return mb_rtrim($parent->path_cache, '/') . '/' . $name;
    }

    /**
     * Rebuilds `path_cache` for everything below `$folder`, level by level through `parent_id`. The folder's own
     * `path_cache` is already new. Never matches on the path text: same-named siblings and `_`/`%` in names would
     * hit folders outside the subtree.
     */
    private function rewriteDescendantPaths(MediaFolder $folder): void
    {
        $tenantId = $this->tenantContext->get()->getId();
        /** @var array<string, string> $level folder id => its fresh path_cache */
        $level = [$folder->id => $folder->path_cache];
        $seen  = [$folder->id => true];

        while ([] !== $level) {
            $next = [];

            MediaFolder::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('parent_id', array_keys($level))
                ->get()
                ->each(function (MediaFolder $child) use ($level, &$next, &$seen): void {
                    if (isset($seen[$child->id])) {
                        return;
                    }

                    $seen[$child->id] = true;
                    $path             = mb_rtrim($level[$child->parent_id], '/') . '/' . $child->name;

                    $child->forceFill(['path_cache' => $path])->save();
                    $next[$child->id] = $path;
                });

            $level = $next;
        }
    }
}
