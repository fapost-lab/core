<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Services\ChannelLimitInspector;
use App\Domains\Media\Services\StorageLimitMessage;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\UpdateFileRequest;
use App\Http\Requests\Media\UploadFileRequest;
use App\Http\Resources\Media\MediaFileResource;
use Fapost\Foundation\Media\Enums\MediaKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FilesController extends Controller
{
    public function __construct(
        private readonly MediaUploaderInterface $uploader,
        private readonly MediaServiceInterface $mediaService,
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantMediaDisk $tenantMediaDisk,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MediaFile::class);

        $tenantId = $this->tenantContext->get()->getId();

        $query = MediaFile::query()
            ->with('blob')
            ->where('tenant_id', $tenantId);

        if ($request->has('folder_id')) {
            $query->where('folder_id', $request->input('folder_id'));
        }

        if ($request->filled('kind')) {
            $query->where('kind', (string)$request->input('kind'));
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $perPage = max(1, min(100, (int)$request->input('per_page', 25)));

        return MediaFileResource::collection($query->latest()->paginate($perPage));
    }

    public function show(string $fileId): MediaFileResource
    {
        $file = $this->resolveFile($fileId, withTrashed: true);
        $this->authorize('view', $file);

        return MediaFileResource::make($file->loadMissing('blob'));
    }

    public function store(UploadFileRequest $request, ChannelLimitInspector $limits): JsonResponse
    {
        $this->authorize('create', MediaFile::class);

        $upload   = $request->file('file');
        $folderId = $request->input('folder_id');
        $folder   = $this->resolveFolder($folderId);
        $name     = (string)($request->input('name') ?? $upload->getClientOriginalName());

        try {
            $media = $this->uploader->uploadFromUploadedFile(
                file: $upload,
                folder: $folder,
                name: $name,
                source: MediaSource::Upload,
                uploadedBy: (string)$request->user()?->getAuthIdentifier(),
            );
        } catch (StorageLimitReachedException $exception) {
            return response()->json([
                'message' => StorageLimitMessage::for($exception),
                'error'   => StorageLimitReachedException::ERROR_KEY,
                'limit'   => $exception->limit,
                'used'    => $exception->used,
                'needed'  => $exception->incoming,
            ], 422);
        }

        // Dedup detection: more than one MediaFile pointing at the same blob means the
        // uploader matched an existing blob instead of creating one.
        $deduplicated = MediaFile::query()->where('blob_id', $media->blob_id)->count() > 1;

        $kind             = MediaKind::fromMimeType($media->blob->mime_type ?? '');
        $providerWarnings = $limits->evaluate($kind, (int)($media->blob->size ?? 0));

        $resource = MediaFileResource::make($media->loadMissing('blob'))
            ->additional(['deduplicated' => $deduplicated]);

        $payload                      = $resource->response()->getData(true);
        $payload['provider_warnings'] = $providerWarnings;

        return response()->json($payload, 201);
    }

    public function update(UpdateFileRequest $request, string $fileId): MediaFileResource
    {
        $file = $this->resolveFile($fileId);
        $this->authorize('update', $file);

        if ($request->has('name')) {
            $file = $this->mediaService->rename($file, (string)$request->input('name'));
        }

        if ($request->has('folder_id')) {
            $folder = $this->resolveFolder($request->input('folder_id'));
            $file   = $this->mediaService->move($file, $folder);
        }

        return MediaFileResource::make($file->fresh()->loadMissing('blob'));
    }

    public function destroy(string $fileId): JsonResponse
    {
        $file = $this->resolveFile($fileId);
        $this->authorize('delete', $file);

        $this->mediaService->softDelete($file);

        return response()->json(status: 204);
    }

    public function restore(string $fileId): MediaFileResource
    {
        $file = $this->resolveFile($fileId, withTrashed: true);
        $this->authorize('restore', $file);

        $file = $this->mediaService->restore($file);

        return MediaFileResource::make($file->loadMissing('blob'));
    }

    public function forceDestroy(Request $request, string $fileId): JsonResponse
    {
        $file = $this->resolveFile($fileId, withTrashed: true);
        $this->authorize('forceDelete', $file);

        $referenceCount = $file->references()->count();

        if ($referenceCount > 0 && ! $request->boolean('force')) {
            return response()->json([
                'error'      => 'has_references',
                'message'    => 'Media file has active references; pass ?force=true to delete anyway.',
                'references' => $referenceCount,
            ], 409);
        }

        $this->mediaService->forceDelete($file);

        return response()->json(status: 204);
    }

    public function raw(string $fileId): BinaryFileResponse|StreamedResponse
    {
        $file = $this->resolveFile($fileId, withTrashed: true);

        if (null !== $file->deleted_at) {
            throw ValidationException::withMessages(['file' => __('Media file has been deleted.')]);
        }

        $disk = $this->tenantMediaDisk->resolve($this->tenantContext->get());
        $blob = $file->loadMissing('blob')->blob;

        if (null === $blob || ! $disk->exists($blob->storage_path)) {
            abort(404);
        }

        return $disk->response(
            $blob->storage_path,
            $file->name,
            ['Content-Type' => $blob->mime_type],
        );
    }

    private function resolveFile(string $fileId, bool $withTrashed = false): MediaFile
    {
        $tenantId = $this->tenantContext->get()->getId();
        $query    = MediaFile::query()->where('tenant_id', $tenantId)->whereKey($fileId);

        if ($withTrashed) {
            $query->withTrashed();
        }

        $file = $query->first();

        if (null === $file) {
            throw new AuthorizationException();
        }

        return $file;
    }

    private function resolveFolder(?string $folderId): ?MediaFolder
    {
        if (null === $folderId || '' === $folderId) {
            return null;
        }

        $tenantId = $this->tenantContext->get()->getId();

        $folder = MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($folderId)
            ->first();

        if (null === $folder) {
            throw ValidationException::withMessages([
                'folder_id' => __('Folder not found.'),
            ]);
        }

        return $folder;
    }

}
