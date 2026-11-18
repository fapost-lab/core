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

        $this->assertRoomUnder($parent, $attribute, $this->height($folder));

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
     * Every folder of the tenant depth-first through `parent_id`, siblings by name: a parent comes right before its
     * subtree, even when two siblings share a name.
     *
     * @return list<array{id: string, name: string, path: string, parentId: string|null, depth: int, files: int, folders: int}>
     */
    public function tree(): array
    {
        $folders = MediaFolder::query()
            ->where('tenant_id', $this->tenantContext->get()->getId())
            ->withCount(['files', 'children'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $byParent = [];
        $known    = $folders->keyBy('id');

        foreach ($folders as $folder) {
            // A folder whose parent is missing from the tenant is listed at the top rather than lost.
            $key              = null !== $folder->parent_id && $known->has($folder->parent_id) ? $folder->parent_id : '';
            $byParent[$key][] = $folder;
        }

        $rows = [];
        $seen = [];
        $walk = function (string $parentKey, int $depth) use (&$walk, &$rows, &$seen, $byParent): void {
            foreach ($byParent[$parentKey] ?? [] as $folder) {
                if (isset($seen[$folder->id])) {
                    continue;
                }

                $seen[$folder->id] = true;
                $rows[]            = [
                    'id'       => $folder->id,
                    'name'     => $folder->name,
                    'path'     => $folder->path_cache,
                    'parentId' => $folder->parent_id,
                    'depth'    => $depth,
                    'files'    => (int) $folder->getAttribute('files_count'),
                    'folders'  => (int) $folder->getAttribute('children_count'),
                ];
                $walk($folder->id, $depth + 1);
            }
        };
        $walk('', 0);

        return $rows;
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
        foreach ($this->ancestorIds($candidate) as $id) {
            if ($id === $folder->id) {
                return true;
            }
        }

        return false;
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
     * A subtree `$height` levels tall (a single folder is 1) put under `$parent` is refused when its deepest folder
     * would sit below the limit.
     *
     * @throws MediaFolderRuleException
     */
    private function assertRoomUnder(?MediaFolder $parent, string $attribute, int $height = 1): void
    {
        if (null !== $parent && $this->depth($parent) + $height > $this->maxDepth) {
            throw new MediaFolderRuleException($attribute, MediaFolderRuleException::TOO_DEEP, $this->maxDepth);
        }
    }

    /**
     * A root folder is at depth 1; counted through `parent_id`: that is the tree, and `path_cache` is only a derived breadcrumb.
     */
    private function depth(MediaFolder $folder): int
    {
        return count($this->ancestorIds($folder));
    }

    /**
     * The folder itself, then its parent and so on up to the root.
     *
     * @return list<string>
     */
    private function ancestorIds(MediaFolder $folder): array
    {
        $ids    = [$folder->id];
        $cursor = $folder->parent_id;

        while (null !== $cursor && ! in_array($cursor, $ids, true)) {
            $ids[]  = $cursor;
            $cursor = MediaFolder::query()
                ->where('tenant_id', $folder->tenant_id)
                ->whereKey($cursor)
                ->value('parent_id');
        }

        return $ids;
    }

    /**
     * Levels of the subtree rooted at `$folder`, itself included (a folder with no subfolders is 1), found through
     * `parent_id`.
     */
    private function height(MediaFolder $folder): int
    {
        $height = 0;
        $level  = [$folder->id];
        $seen   = [$folder->id => true];

        while ([] !== $level) {
            ++$height;
            $next = [];

            MediaFolder::query()
                ->where('tenant_id', $folder->tenant_id)
                ->whereIn('parent_id', $level)
                ->pluck('id')
                ->each(function (string $id) use (&$next, &$seen): void {
                    if (! isset($seen[$id])) {
                        $seen[$id] = true;
                        $next[]    = $id;
                    }
                });

            $level = $next;
        }

        return $height;
    }
}
