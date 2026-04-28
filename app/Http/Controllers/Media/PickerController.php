<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Media\MediaFileResource;
use App\Http\Resources\Media\MediaFolderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Single endpoint optimised for the Vue media picker.
 *
 * Returns the contents of one folder level — both subfolders and filtered files — so the
 * picker only needs one round-trip per navigation. Folders are always returned regardless
 * of the kind filter so the user can drill into nested folders that contain matching media.
 */
final class PickerController extends Controller
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
    ) {
    }

    public function contents(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MediaFile::class);

        $tenantId = $this->tenantContext->get()->getId();
        $folderId = $request->input('folder_id');
        $kind     = $request->input('kind');

        $currentFolder = null;
        $breadcrumbs   = [];

        if (is_string($folderId) && '' !== $folderId) {
            $currentFolder = MediaFolder::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($folderId)
                ->first();

            if (null === $currentFolder) {
                abort(404);
            }

            $breadcrumbs = $this->breadcrumbs($currentFolder);
        }

        $subfolders = MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->where('parent_id', $currentFolder?->id)
            ->orderBy('name')
            ->get();

        $filesQuery = MediaFile::query()
            ->with('blob')
            ->where('tenant_id', $tenantId)
            ->where('folder_id', $currentFolder?->id);

        if (is_string($kind) && '' !== $kind) {
            $filesQuery->where('kind', $kind);
        }

        $files = $filesQuery->orderBy('name')->get();

        $subfolderPayload = $subfolders->map(function (MediaFolder $folder) use ($kind, $tenantId): array {
            $totalCount = MediaFile::query()
                ->where('tenant_id', $tenantId)
                ->where('folder_id', $folder->id)
                ->count();

            $filteredCount = is_string($kind) && '' !== $kind
                ? MediaFile::query()
                    ->where('tenant_id', $tenantId)
                    ->where('folder_id', $folder->id)
                    ->where('kind', $kind)
                    ->count()
                : $totalCount;

            return MediaFolderResource::make($folder)
                ->additional([
                    'file_count_total'    => $totalCount,
                    'file_count_filtered' => $filteredCount,
                ])
                ->response()
                ->getData(true)['data'];
        })->all();

        return response()->json([
            'current_folder' => null === $currentFolder
                ? null
                : [
                    'id'          => $currentFolder->id,
                    'name'        => $currentFolder->name,
                    'breadcrumbs' => $breadcrumbs,
                ],
            'subfolders' => $subfolderPayload,
            'files'      => MediaFileResource::collection($files)->response()->getData(true)['data'],
        ]);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function breadcrumbs(MediaFolder $folder): array
    {
        $crumbs = [];
        $cursor = $folder;

        while (null !== $cursor) {
            array_unshift($crumbs, ['id' => $cursor->id, 'name' => $cursor->name]);
            $cursor = $cursor->parent;
        }

        return $crumbs;
    }
}
