<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Exceptions\MediaFileInUseException;
use App\Domains\Media\Exceptions\MediaFolderRuleException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFileReference;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Preview\MediaPreviewRegistry;
use App\Domains\Media\Services\MediaFolderService;
use App\Domains\Media\Services\MediaLibraryService;
use App\Domains\Media\Services\StorageLimitMessage;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Admin\MediaFileIdsRequest;
use App\Http\Requests\Admin\RenameMediaFileRequest;
use App\Http\Requests\Admin\UploadMediaFilesRequest;
use Fapost\Foundation\Media\Enums\MediaKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * The media library in the admin panel, inside the console shell's admin mode, with the behaviour of the Filament
 * resource it replaces: a folder tree beside the files of the open folder, uploads, rename, move, the trash and the
 * file's own page with a preview.
 *
 * Bytes never pass through this controller's props: a preview or a download is a short-lived signed `media.files.raw`
 * URL, and a file's storage path stays on the server. Every query is limited to the current tenant by the services.
 * Folders are written by {@see MediaFolderController}.
 */
final class MediaController extends Controller
{
    private const string TRASH_WITH = 'with';

    private const string TRASH_ONLY = 'only';

    public function __construct(
        private readonly MediaLibraryService $library,
        private readonly MediaFolderService $folders,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', MediaFile::class);

        $folder = $this->openFolder($request);
        $trash  = $this->trashFilter($request);
        $query  = $this->library->query($folder);

        match ($trash) {
            self::TRASH_WITH => null,
            self::TRASH_ONLY => $query->whereNotNull($query->qualifyColumn('deleted_at')),
            default          => $query->whereNull($query->qualifyColumn('deleted_at')),
        };

        $table = new DataTable(
            sortable: ['name', 'created_at'],
            searchable: ['name'],
            defaultSort: '-created_at',
            filters: [
                // Both are applied above, because their absence means something too (the root, no trash).
                'folder'  => static fn (Builder $query, string $value): bool => $value === $folder?->id,
                'trashed' => static fn (Builder $query, string $value): bool => $value === $trash,
                'kind'    => static function (Builder $query, string $value): bool {
                    $kind = MediaKind::tryFrom($value);

                    if (null === $kind) {
                        return false;
                    }

                    $query->where($query->qualifyColumn('kind'), $kind->value);

                    return true;
                },
            ],
        );

        $canManage = Gate::allows('create', MediaFile::class);

        return Inertia::render('Console/Media/Index', [
            'table'       => $table->respond($request, $query, fn (MediaFile $file): array => $this->row($file)),
            'folder'      => null === $folder ? null : ['id' => $folder->id, 'name' => $folder->name],
            'breadcrumbs' => null === $folder ? [] : $this->folders->breadcrumbs($folder),
            'tree'        => array_map(fn (array $node): array => [
                ...$node,
                'updateUrl'  => $this->url('folders.update', ['folder' => $node['id']]),
                'destroyUrl' => $this->url('folders.destroy', ['folder' => $node['id']]),
            ], $this->folders->tree()),
            'kinds' => array_map(
                static fn (MediaKind $kind): array => ['value' => $kind->value, 'label' => __('media.kinds.' . $kind->value)],
                MediaKind::cases(),
            ),
            'upload' => [
                'maxFiles' => UploadMediaFilesRequest::MAX_FILES,
                'maxBytes' => self::maxUploadBytes(),
                'maxSize'  => Number::fileSize(self::maxUploadBytes()),
                'accept'   => implode(',', (array) config('media.allowed_mime_types', [])),
            ],
            'can'  => ['manage' => $canManage],
            'urls' => [
                'index'       => $this->url('index'),
                'upload'      => $this->url('upload'),
                'move'        => $this->url('move'),
                'destroyMany' => $this->url('destroy-many'),
                'storeFolder' => $this->url('folders.store'),
            ],
        ]);
    }

    /**
     * The file's own page: a preview by its type, what it is, where it is used, and a download. Read-only, as in
     * Filament; a file in the trash has no preview and no download, since its signed URL would be refused.
     */
    public function show(string $record, MediaServiceInterface $media, MediaPreviewRegistry $previews): Response
    {
        $file = $this->library->find($record)->loadMissing(['blob', 'folder']);

        Gate::authorize('view', $file);

        $url = $media->signedUrl($file);

        return Inertia::render('Console/Media/Show', [
            'file' => [
                'id'        => $file->id,
                'name'      => $file->name,
                'kind'      => $file->kind->value,
                'kindLabel' => __('media.kinds.' . $file->kind->value),
                'mimeType'  => $file->blob?->mime_type,
                'size'      => Number::fileSize((int) ($file->blob?->size ?? 0), precision: 1),
                'folder'    => $file->folder?->path_cache,
                'source'    => $file->source->value,
                'createdAt' => $file->created_at?->toIso8601String(),
                'trashed'   => $file->trashed(),
            ],
            'preview' => null === $url ? null : [
                'type' => $previews->resolve($file)?->kind,
                'url'  => $url,
            ],
            'references' => $file->references()
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (MediaFileReference $reference): array => self::reference($reference))
                ->values()
                ->all(),
            'urls' => [
                'index' => $this->url('index', null === $file->folder_id ? [] : ['filter' => ['folder' => $file->folder_id]]),
            ],
        ]);
    }

    /**
     * Stores the batch until the storage limit refuses a file; that refusal is a toast saying why and how many were
     * saved, never an error page.
     */
    public function upload(UploadMediaFilesRequest $request): RedirectResponse
    {
        try {
            $folder = $this->folders->resolve($request->folderId(), 'folder_id');
        } catch (MediaFolderRuleException $exception) {
            throw $exception->toValidationException();
        }

        $result = $this->library->upload($request->uploads(), $folder, (string) $request->user()?->getAuthIdentifier());
        $batch  = $request->batch();
        $saved  = $batch['savedBefore'] + $result['saved'];

        if (null !== $result['refused']) {
            $message = StorageLimitMessage::for($result['refused']);

            if ($batch['total'] > 1) {
                $message .= ' ' . __('media.errors.storage_limit_saved', ['saved' => $saved, 'total' => $batch['total']]);
            }

            return $this->back($message, 'error');
        }

        // A request in the middle of a batch says nothing: the last one reports the whole batch.
        if ($saved < $batch['total']) {
            return redirect()->back(fallback: $this->url('index'));
        }

        return $this->back(__('media.notifications.uploaded', ['count' => $saved]));
    }

    public function update(RenameMediaFileRequest $request, string $record): RedirectResponse
    {
        $file = $this->library->find($record);

        Gate::authorize('update', $file);

        $this->library->rename($file, $request->name());

        return $this->back(trans('console.media.renamed'));
    }

    /**
     * Moves the picked files into a folder, or to the root; one file is a list of one.
     */
    public function move(MediaFileIdsRequest $request): RedirectResponse
    {
        try {
            $folder = $this->folders->resolve($request->folderId(), 'folder_id');
        } catch (MediaFolderRuleException $exception) {
            throw $exception->toValidationException();
        }

        $files = $this->library->findMany($request->ids())
            ->filter(static fn (MediaFile $file): bool => Gate::allows('update', $file));

        return $this->back(__('media.notifications.files_moved', ['count' => $this->library->move($files, $folder)]));
    }

    public function destroy(string $record): RedirectResponse
    {
        $file = $this->library->find($record);

        Gate::authorize('delete', $file);

        $this->library->softDelete($file->newCollection([$file]));

        return $this->back(trans('console.media.deleted'));
    }

    public function destroyMany(MediaFileIdsRequest $request): RedirectResponse
    {
        $files = $this->library->findMany($request->ids())
            ->filter(static fn (MediaFile $file): bool => Gate::allows('delete', $file));

        return $this->back(trans('console.media.deleted_many', ['count' => $this->library->softDelete($files)]));
    }

    public function restore(string $record): RedirectResponse
    {
        $file = $this->library->find($record);

        Gate::authorize('restore', $file);

        $this->library->restore($file);

        return $this->back(trans('console.media.restored'));
    }

    /**
     * A file a flow still uses stays: the refusal is a toast, as Filament refused the action.
     */
    public function forceDestroy(string $record): RedirectResponse
    {
        $file = $this->library->find($record);

        Gate::authorize('forceDelete', $file);

        try {
            $this->library->forceDelete($file);
        } catch (MediaFileInUseException) {
            return $this->back(__('media.errors.has_references'), 'error');
        }

        return $this->back(trans('console.media.force_deleted'));
    }

    /**
     * The largest file the screen may send: the media limit, or less when one request of the server cannot carry it
     * (`upload_max_filesize`, `post_max_size`).
     */
    private static function maxUploadBytes(): int
    {
        $media   = (int) config('media.max_size_bytes', 100 * 1024 * 1024);
        $request = (int) SymfonyUploadedFile::getMaxFilesize();

        return $request > 0 ? min($media, $request) : $media;
    }

    /**
     * A reference as Filament's usage modal showed it: what points at the file, by the names it had when it was saved.
     *
     * @return array{id: string, type: string, name: string, node: string|null}
     */
    private static function reference(MediaFileReference $reference): array
    {
        $snapshot = is_array($reference->snapshot) ? $reference->snapshot : [];
        $node     = $snapshot['node_label'] ?? $snapshot['node_id'] ?? null;
        $typeKey  = 'media.references.types.' . $reference->reference_type;

        return [
            'id'   => $reference->id,
            'type' => trans()->has($typeKey) ? (string) __($typeKey) : $reference->reference_type,
            'name' => (string) ($snapshot['flow_name'] ?? $reference->reference_id),
            'node' => null === $node ? null : (string) $node,
        ];
    }

    /**
     * The open folder from `filter[folder]` (or Filament's `folder`): the root when it is absent or not a folder of this
     * tenant.
     */
    private function openFolder(Request $request): ?MediaFolder
    {
        $filters = $request->query('filter');
        // `?folder=` is how Filament's links named it; old bookmarks keep working.
        $value = is_array($filters) && array_key_exists('folder', $filters) ? $filters['folder'] : $request->query('folder');
        $id    = is_string($value) ? mb_trim($value) : '';

        if ('' === $id) {
            return null;
        }

        try {
            return $this->folders->find($id);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /**
     * `filter[trashed]`: `with` lists the trash too, `only` lists just the trash, anything else leaves it out.
     */
    private function trashFilter(Request $request): string
    {
        $filters = $request->query('filter');
        $value   = is_array($filters) ? ($filters['trashed'] ?? null) : null;

        return in_array($value, [self::TRASH_WITH, self::TRASH_ONLY], true) ? $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MediaFile $file): array
    {
        $parameters = ['record' => $file->id];

        return [
            'id'         => $file->id,
            'name'       => $file->name,
            'kind'       => $file->kind->value,
            'kindLabel'  => __('media.kinds.' . $file->kind->value),
            'size'       => Number::fileSize((int) ($file->blob?->size ?? 0), precision: 1),
            'references' => (int) $file->getAttribute('references_count'),
            'trashed'    => $file->trashed(),
            'createdAt'  => $file->created_at?->toIso8601String(),
            'folderId'   => $file->folder_id,
            'viewUrl'    => $this->url('view', $parameters),
            'updateUrl'  => $this->url('update', $parameters),
            'destroyUrl' => $this->url('destroy', $parameters),
            'restoreUrl' => $this->url('restore', $parameters),
            'forceUrl'   => $this->url('force', $parameters),
        ];
    }

    /**
     * @param  'success'|'error'  $kind
     */
    private function back(string $message, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->back(fallback: $this->url('index'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        $name = match ($action) {
            'index', 'view' => 'filament.admin.resources.media.' . $action,
            default         => 'console.admin.media.' . $action,
        };

        return route($name, $parameters, false);
    }
}
