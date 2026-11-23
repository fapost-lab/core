<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Contracts\MediaFolderRepositoryInterface;
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
 * Every tree write (create, rename, move, delete) runs in a transaction that takes the tenant's tree lock first; see
 * {@see self::move()}. The writes themselves (and the `path_cache` rewrite of descendants) stay in
 * {@see MediaServiceInterface}.
 */
final readonly class MediaFolderService
{
    /**
     * @param  int  $maxDepth  config `media.folder.max_depth`, bound in MediaServiceProvider
     */
    public function __construct(
        private TenantContextInterface $tenantContext,
        private MediaServiceInterface $media,
        private MediaFolderRepositoryInterface $folders,
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
        $connection = $parent?->getConnection() ?? (new MediaFolder())->getConnection();

        return $connection->transaction(function () use ($name, $parent, $createdBy): MediaFolder {
            $this->lockTree($parent?->tenant_id ?? $this->tenantContext->get()->getId());

            // The parent may have been moved deeper since the caller loaded it.
            $parent = $this->reread($parent, 'parent_id');
            $this->assertRoomUnder($parent, 'parent_id');

            return $this->media->createFolder(name: $name, parent: $parent, createdBy: $createdBy);
        });
    }

    /**
     * @throws ModelNotFoundException<MediaFolder> when the folder was deleted since it was loaded
     */
    public function rename(MediaFolder $folder, string $name): MediaFolder
    {
        return $folder->getConnection()->transaction(function () use ($folder, $name): MediaFolder {
            $this->lockTree($folder->tenant_id);

            // The folder may have been moved since it was loaded; the new `path_cache` is built from its parent now.
            $folder = $folder->fresh() ?? throw (new ModelNotFoundException())->setModel(MediaFolder::class, [$folder->id]);

            return $this->media->renameFolder($folder, $name);
        });
    }

    /**
     * Tree writes are serialised per tenant: the subtree and depth checks read the tree and the write changes it, so two
     * concurrent moves (A into B, B into A) would each pass on the tree as it was and together close a `parent_id`
     * cycle cut off from the root; a create under X racing a move of X, or a rename racing a move, would build on a
     * parent that is no longer where it was. So create, rename, move and delete all take the same lock. One
     * transaction-scoped advisory lock per tenant is taken before anything is read
     * (a lock on rows would have to cover the whole ancestor chain in a stable order, and the chain is only known
     * after reading it; a single lock key cannot deadlock). Tree writes are rare, so the wait is not felt. The lock
     * is behind {@see MediaFolderRepositoryInterface::lockTree()}. The models are re-read under the lock, since the
     * ones the caller holds may predate a move that just committed.
     *
     * @throws MediaFolderRuleException
     */
    public function move(MediaFolder $folder, ?MediaFolder $parent, string $attribute = 'parent_id'): MediaFolder
    {
        return $folder->getConnection()->transaction(function () use ($folder, $parent, $attribute): MediaFolder {
            $this->lockTree($folder->tenant_id);

            // Both models may predate a move that just committed; a stale folder would have only its path rewritten.
            $folder = $folder->fresh() ?? throw (new ModelNotFoundException())->setModel(MediaFolder::class, [$folder->id]);
            $parent = $this->reread($parent, $attribute);
            $chain  = null === $parent ? [] : $this->ancestorChain($parent);

            if (in_array($folder->id, array_map(static fn (MediaFolder $f): string => $f->id, $chain), true)) {
                throw new MediaFolderRuleException($attribute, MediaFolderRuleException::OWN_SUBTREE);
            }

            $this->assertRoomAtDepth(count($chain), $attribute, $this->height($folder));

            return $this->media->moveFolder($folder, $parent);
        });
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
        $folder->getConnection()->transaction(function () use ($folder, $target): void {
            $this->lockTree($folder->tenant_id);

            $target = $this->reread($target, 'move_to');

            if (null !== $target && $this->isInSubtree($target, $folder)) {
                throw new MediaFolderRuleException('move_to', MediaFolderRuleException::OWN_SUBTREE);
            }

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
        return array_map(
            static fn (MediaFolder $ancestor): array => ['id' => $ancestor->id, 'name' => $ancestor->name],
            array_reverse($this->ancestorChain($folder)),
        );
    }

    /**
     * Whether `$candidate` is `$folder` itself or lies inside it.
     */
    public function isInSubtree(MediaFolder $candidate, MediaFolder $folder): bool
    {
        foreach ($this->ancestorChain($candidate) as $ancestor) {
            if ($ancestor->id === $folder->id) {
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
        $this->assertRoomAtDepth(null === $parent ? 0 : count($this->ancestorChain($parent)), $attribute, $height);
    }

    private function assertRoomAtDepth(int $parentDepth, string $attribute, int $height): void
    {
        if ($parentDepth > 0 && $parentDepth + $height > $this->maxDepth) {
            throw new MediaFolderRuleException($attribute, MediaFolderRuleException::TOO_DEEP, $this->maxDepth);
        }
    }

    /**
     * The folder as it is now; a refusal on the field when it was deleted since it was loaded.
     *
     * @throws MediaFolderRuleException
     */
    private function reread(?MediaFolder $folder, string $attribute): ?MediaFolder
    {
        if (null === $folder) {
            return null;
        }

        return $folder->fresh() ?? throw new MediaFolderRuleException(
            $attribute,
            'parent_id' === $attribute ? MediaFolderRuleException::PARENT_NOT_FOUND : MediaFolderRuleException::NOT_FOUND,
        );
    }

    /**
     * Takes the tenant's tree lock until the surrounding transaction ends; see {@see self::move()}.
     */
    private function lockTree(string $tenantId): void
    {
        $this->folders->lockTree($tenantId);
    }

    /**
     * The folder itself, then its parent and so on up to the root, walked through `parent_id` (that is the tree;
     * `path_cache` is only a derived breadcrumb), so a root folder is at depth 1 and the chain is as long as its depth.
     * One walk serves breadcrumbs, depth and the subtree check; a loop already in the data ends it.
     *
     * @return non-empty-list<MediaFolder>
     */
    private function ancestorChain(MediaFolder $folder): array
    {
        $chain  = [$folder];
        $seen   = [$folder->id => true];
        $cursor = $folder->parent_id;

        while (null !== $cursor && ! isset($seen[$cursor])) {
            $parent = MediaFolder::query()->where('tenant_id', $folder->tenant_id)->whereKey($cursor)->first();

            if (null === $parent) {
                break;
            }

            $seen[$cursor] = true;
            $chain[]       = $parent;
            $cursor        = $parent->parent_id;
        }

        return $chain;
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
