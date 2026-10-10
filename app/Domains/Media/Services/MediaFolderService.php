<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Exceptions\MediaFolderRuleException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * The rules of the folder tree, for every entry point (the REST API and the admin screen): a folder is found inside
 * the current tenant only, the tree is at most `media.folder.max_depth` deep, a folder never moves into its own
 * subtree, and deleting a folder first moves what it holds somewhere else.
 *
 * The writes themselves (and the `path_cache` rewrite of descendants) stay in {@see MediaServiceInterface}.
 */
final readonly class MediaFolderService
{
    /**
     * @param  int  $maxDepth  config `media.folder.max_depth`, bound in MediaServiceProvider
     */
    public function __construct(
        private TenantContextInterface $tenantContext,
        private MediaServiceInterface $media,
        private int $maxDepth = 10,
    ) {
    }

    /**
     * A folder of the current tenant; another tenant's id, or one that is not an id at all, is not found.
     *
     * @throws ModelNotFoundException<MediaFolder>
     */
    public function find(string $id): MediaFolder
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(MediaFolder::class, [$id]);
        }

        return MediaFolder::query()
            ->where('tenant_id', $this->tenantContext->get()->getId())
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * The folder a form field names: none for an empty value (the root), a refusal on that field for an unknown one.
     *
     * @throws MediaFolderRuleException
     */
    public function resolve(?string $id, string $attribute): ?MediaFolder
    {
        if (null === $id || '' === $id) {
            return null;
        }

        try {
            return $this->find($id);
        } catch (ModelNotFoundException) {
            throw new MediaFolderRuleException(
                $attribute,
                'parent_id' === $attribute ? MediaFolderRuleException::PARENT_NOT_FOUND : MediaFolderRuleException::NOT_FOUND,
            );
        }
    }

    /**
     * @throws MediaFolderRuleException
     */
    public function create(string $name, ?MediaFolder $parent, ?string $createdBy = null): MediaFolder
    {
        $this->assertRoomUnder($parent, 'parent_id');

        return $this->media->createFolder(name: $name, parent: $parent, createdBy: $createdBy);
    }

    public function rename(MediaFolder $folder, string $name): MediaFolder
    {
        return $this->media->renameFolder($folder, $name);
    }

    /**
     * @throws MediaFolderRuleException
     */
    public function move(MediaFolder $folder, ?MediaFolder $parent, string $attribute = 'parent_id'): MediaFolder
    {
        if (null !== $parent && $this->isInSubtree($parent, $folder)) {
            throw new MediaFolderRuleException($attribute, MediaFolderRuleException::OWN_SUBTREE);
        }

        $this->assertRoomUnder($parent, $attribute);

        return $this->media->moveFolder($folder, $parent);
    }

    /**
     * How many files (not in the trash) and direct subfolders a folder holds.
     *
     * @return array{files: int, folders: int}
     */
    public function contents(MediaFolder $folder): array
    {
        return [
            'files'   => $folder->files()->count(),
            'folders' => $this->children($folder)->count(),
        ];
    }

    /**
     * Deletes a folder after moving its direct files (those in the trash too, so a restore brings them back where the
     * rest went; one UPDATE, so no model events) and its direct subfolders into `$target`, or to the root. Subfolders move through
     * {@see MediaServiceInterface::moveFolder()}, which rewrites their descendants' paths; the database alone would
     * leave them at the root with a stale `path_cache`. All of it or nothing.
     *
     * @throws MediaFolderRuleException when the target is the folder itself or inside it
     */
    public function deleteMovingContents(MediaFolder $folder, ?MediaFolder $target): void
    {
        if (null !== $target && $this->isInSubtree($target, $folder)) {
            throw new MediaFolderRuleException('move_to', MediaFolderRuleException::OWN_SUBTREE);
        }

        $folder->getConnection()->transaction(function () use ($folder, $target): void {
            // One statement, not a walk in chunks: paging by offset over the column being changed skips rows, and the
            // skipped files would fall to the root through the foreign key. A file has no observers to miss.
            MediaFile::query()
                ->withTrashed()
                ->where('tenant_id', $folder->tenant_id)
                ->where('folder_id', $folder->id)
                ->update(['folder_id' => $target?->id]);

            $this->children($folder)
                ->get()
                ->each(fn (MediaFolder $child): MediaFolder => $this->move($child, $target, 'move_to'));

            $this->media->deleteFolder($folder);
        });
    }

    /**
     * Every folder of the tenant in path order, which lists a parent right before its subtree.
     *
     * @return list<array{id: string, name: string, path: string, parentId: string|null, depth: int, files: int, folders: int}>
     */
    public function tree(): array
    {
        $folders = MediaFolder::query()
            ->where('tenant_id', $this->tenantContext->get()->getId())
            ->withCount(['files', 'children'])
            ->orderBy('path_cache')
            ->get();

        return $folders->map(fn (MediaFolder $folder): array => [
            'id'       => $folder->id,
            'name'     => $folder->name,
            'path'     => $folder->path_cache,
            'parentId' => $folder->parent_id,
            'depth'    => $this->depth($folder) - 1,
            'files'    => (int) $folder->getAttribute('files_count'),
            'folders'  => (int) $folder->getAttribute('children_count'),
        ])->values()->all();
    }

    /**
     * The folder and its ancestors, the root's child first.
     *
     * @return list<array{id: string, name: string}>
     */
    public function breadcrumbs(MediaFolder $folder): array
    {
        $crumbs = [];
        $cursor = $folder;

        while (null !== $cursor) {
            array_unshift($crumbs, ['id' => $cursor->id, 'name' => $cursor->name]);
            $cursor = null === $cursor->parent_id
                ? null
                : MediaFolder::query()->where('tenant_id', $folder->tenant_id)->whereKey($cursor->parent_id)->first();
        }

        return $crumbs;
    }

    /**
     * Whether `$candidate` is `$folder` itself or lies inside it.
     */
    public function isInSubtree(MediaFolder $candidate, MediaFolder $folder): bool
    {
        return $candidate->id === $folder->id
            || str_starts_with($candidate->path_cache, mb_rtrim($folder->path_cache, '/') . '/');
    }

    /**
     * @return Builder<MediaFolder>
     */
    private function children(MediaFolder $folder): Builder
    {
        return MediaFolder::query()
            ->where('tenant_id', $folder->tenant_id)
            ->where('parent_id', $folder->id);
    }

    /**
     * A new child of `$parent` would sit one level below it: refused when that is deeper than the limit.
     *
     * @throws MediaFolderRuleException
     */
    private function assertRoomUnder(?MediaFolder $parent, string $attribute): void
    {
        if (null !== $parent && $this->depth($parent) + 1 > $this->maxDepth) {
            throw new MediaFolderRuleException($attribute, MediaFolderRuleException::TOO_DEEP, $this->maxDepth);
        }
    }

    /**
     * A root folder is at depth 1.
     */
    private function depth(MediaFolder $folder): int
    {
        $path = mb_trim($folder->path_cache, '/');

        return '' === $path ? 1 : mb_substr_count($path, '/') + 1;
    }
}
