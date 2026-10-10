<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domains\Media\Exceptions\MediaFolderRuleException;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Services\MediaFolderService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\CreateFolderRequest;
use App\Http\Requests\Media\UpdateFolderRequest;
use App\Http\Resources\Media\MediaFolderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FoldersController extends Controller
{
    public function __construct(
        private readonly MediaFolderService $folders,
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

        try {
            $folder = $this->folders->create(
                name: (string)$request->input('name'),
                parent: $this->folders->resolve($request->input('parent_id'), 'parent_id'),
            );
        } catch (MediaFolderRuleException $exception) {
            throw $exception->toValidationException();
        }

        return MediaFolderResource::make($folder)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateFolderRequest $request, MediaFolder $folder): MediaFolderResource
    {
        $this->authorize('update', $folder);

        $newName = $request->input('name');

        try {
            if (is_string($newName)) {
                $folder = $this->folders->rename($folder, $newName);
            }

            if ($request->has('parent_id')) {
                $folder = $this->folders->move($folder, $this->folders->resolve($request->input('parent_id'), 'parent_id'));
            }
        } catch (MediaFolderRuleException $exception) {
            throw $exception->toValidationException();
        }

        return MediaFolderResource::make($folder);
    }

    /**
     * A folder that is not empty needs `force=true`; its files and subfolders then move to the root.
     */
    public function destroy(Request $request, MediaFolder $folder): JsonResponse
    {
        $this->authorize('delete', $folder);

        $contents = $this->folders->contents($folder);

        if (($contents['files'] > 0 || $contents['folders'] > 0) && ! $request->boolean('force')) {
            return response()->json([
                'error'   => 'folder_not_empty',
                'message' => 'Folder is not empty. Pass ?force=true to delete it and move its files and subfolders to the root.',
            ], 409);
        }

        $this->folders->deleteMovingContents($folder, null);

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
}
