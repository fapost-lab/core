<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\CreateFolderRequest;
use App\Http\Requests\Media\UpdateFolderRequest;
use App\Http\Resources\Media\MediaFolderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

final class FoldersController extends Controller
{
    public function __construct(
        private readonly MediaServiceInterface $mediaService,
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MediaFolder::class);

        $tenantId = $this->tenantContext->get()->getId();

        $folders = MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('path_cache')
            ->get();

        return MediaFolderResource::collection($folders);
    }

    public function show(MediaFolder $folder): MediaFolderResource
    {
        $this->authorize('view', $folder);

        return MediaFolderResource::make($folder)->additional([
            'breadcrumbs' => $this->buildBreadcrumbs($folder),
        ]);
    }

    public function store(CreateFolderRequest $request): JsonResponse
    {
        $this->authorize('create', MediaFolder::class);

        $parent = $this->resolveParent($request->input('parent_id'));

        $this->assertDepthAllowed($parent, addingChild: true);

        $folder = $this->mediaService->createFolder(
            name: (string)$request->input('name'),
            parent: $parent,
        );

        return MediaFolderResource::make($folder)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateFolderRequest $request, MediaFolder $folder): MediaFolderResource
    {
        $this->authorize('update', $folder);

        $newName     = $request->input('name');
        $hasParent   = $request->has('parent_id');
        $newParentId = $hasParent ? $request->input('parent_id') : null;

        if (is_string($newName)) {
            $folder = $this->mediaService->renameFolder($folder, $newName);
        }

        if ($hasParent) {
            $newParent = null === $newParentId ? null : $this->resolveParent($newParentId);

            if (null !== $newParent && $this->isDescendantOrSelf($newParent, $folder)) {
                throw ValidationException::withMessages([
                    'parent_id' => __('Folder cannot be moved into its own subtree.'),
                ]);
            }

            $this->assertDepthAllowed($newParent, addingChild: true);

            $folder = $this->mediaService->moveFolder($folder, $newParent);
        }

        return MediaFolderResource::make($folder);
    }

    public function destroy(Request $request, MediaFolder $folder): JsonResponse
    {
        $this->authorize('delete', $folder);

        $hasChildren = MediaFolder::query()->where('parent_id', $folder->id)->exists();
        $hasFiles    = $folder->files()->exists();

        if (($hasChildren || $hasFiles) && ! $request->boolean('force')) {
            return response()->json([
                'error'   => 'folder_not_empty',
                'message' => 'Folder is not empty. Pass ?force=true to delete recursively.',
            ], 409);
        }

        $this->mediaService->deleteFolder($folder);

        return response()->json(status: 204);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function buildBreadcrumbs(MediaFolder $folder): array
    {
        $breadcrumbs = [];
        $cursor      = $folder;

        while (null !== $cursor) {
            array_unshift($breadcrumbs, ['id' => $cursor->id, 'name' => $cursor->name]);
            $cursor = $cursor->parent;
        }

        return $breadcrumbs;
    }

    private function resolveParent(?string $parentId): ?MediaFolder
    {
        if (null === $parentId || '' === $parentId) {
            return null;
        }

        $tenantId = $this->tenantContext->get()->getId();

        $parent = MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($parentId)
            ->first();

        if (null === $parent) {
            throw ValidationException::withMessages([
                'parent_id' => __('Parent folder not found.'),
            ]);
        }

        return $parent;
    }

    private function assertDepthAllowed(?MediaFolder $parent, bool $addingChild): void
    {
        $maxDepth = (int)config('media.folder.max_depth', 10);

        if (null === $parent) {
            return;
        }

        $depth = $this->folderDepth($parent) + ($addingChild ? 1 : 0);

        if ($depth > $maxDepth) {
            throw ValidationException::withMessages([
                'parent_id' => sprintf('Folder depth would exceed the configured limit (%d).', $maxDepth),
            ]);
        }
    }

    private function folderDepth(MediaFolder $folder): int
    {
        $path = mb_trim($folder->path_cache, '/');

        return '' === $path ? 1 : mb_substr_count($path, '/') + 1;
    }

    private function isDescendantOrSelf(MediaFolder $candidate, MediaFolder $folder): bool
    {
        if ($candidate->id === $folder->id) {
            return true;
        }

        return str_starts_with(
            (string)$candidate->path_cache,
            mb_rtrim((string)$folder->path_cache, '/') . '/',
        );
    }
}
