<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\MediaFileInUseException;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The media library as staff manage it: the files of one folder, uploads in a batch, and the writes on files picked
 * from a list. Every query is limited to the current tenant itself; files in the trash are found too, since the
 * library shows them and restores them.
 *
 * Bytes are stored only through {@see MediaUploaderInterface} (the storage limit is checked there) and deleted only
 * through {@see MediaServiceInterface::forceDelete()} (the blob goes with the last file).
 */
final readonly class MediaLibraryService
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private MediaServiceInterface $media,
        private MediaUploaderInterface $uploader,
    ) {
    }

    /**
     * The files of a folder (the root for none), with their blob and how many references point at each.
     *
     * @return Builder<MediaFile>
     */
    public function query(?MediaFolder $folder): Builder
    {
        return MediaFile::query()
            ->withTrashed()
            ->with('blob')
            ->withCount('references')
            ->where('tenant_id', $this->tenantId())
            ->where('folder_id', $folder?->id);
    }

    /**
     * A file of the current tenant, in the trash or not; another tenant's id, or one that is not an id, is not found.
     *
     * @throws ModelNotFoundException<MediaFile>
     */
    public function find(string $id): MediaFile
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(MediaFile::class, [$id]);
        }

        return MediaFile::query()
            ->withTrashed()
            ->where('tenant_id', $this->tenantId())
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * The files of the current tenant among the ids; the others are left out.
     *
     * @param  list<string>  $ids
     *
     * @return Collection<int, MediaFile>
     */
    public function findMany(array $ids): Collection
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => Str::isUuid($id)));

        return MediaFile::query()
            ->withTrashed()
            ->where('tenant_id', $this->tenantId())
            ->whereKey($ids)
            ->get();
    }

    /**
     * Stores the uploads one by one. The first refusal for the storage limit ends the batch: the files before it stay,
     * the rest are not tried (a smaller one might still fit, but a batch that stops is easier to explain).
     *
     * @param  list<UploadedFile>  $files
     *
     * @return array{saved: int, total: int, refused: StorageLimitReachedException|null}
     */
    public function store(array $files, ?MediaFolder $folder, ?string $uploadedBy): array
    {
        $saved = 0;

        foreach ($files as $file) {
            try {
                $this->uploader->uploadFromUploadedFile(
                    file: $file,
                    folder: $folder,
                    name: $file->getClientOriginalName(),
                    source: MediaSource::Upload,
                    uploadedBy: $uploadedBy,
                );
            } catch (StorageLimitReachedException $exception) {
                return ['saved' => $saved, 'total' => count($files), 'refused' => $exception];
            }

            ++$saved;
        }

        return ['saved' => $saved, 'total' => count($files), 'refused' => null];
    }

    public function rename(MediaFile $file, string $name): MediaFile
    {
        return $this->media->rename($file, $name);
    }

    /**
     * @param  Collection<int, MediaFile>  $files
     *
     * @return int how many were moved
     */
    public function move(Collection $files, ?MediaFolder $folder): int
    {
        $files->each(fn (MediaFile $file): MediaFile => $this->media->move($file, $folder));

        return $files->count();
    }

    /**
     * Moves files to the trash, one by one; those already there are skipped. References do not stop it: a flow that
     * sends a file in the trash fails on that step, and the file can be restored.
     *
     * @param  Collection<int, MediaFile>  $files
     *
     * @return int how many were moved to the trash
     */
    public function softDelete(Collection $files): int
    {
        $live = $files->filter(static fn (MediaFile $file): bool => ! $file->trashed());

        $live->each(fn (MediaFile $file) => $this->media->softDelete($file));

        return $live->count();
    }

    public function restore(MediaFile $file): MediaFile
    {
        return $this->media->restore($file);
    }

    /**
     * Deletes a file permanently, unless a flow still references it.
     *
     * @throws MediaFileInUseException
     */
    public function forceDelete(MediaFile $file): void
    {
        $references = $file->references()->count();

        if ($references > 0) {
            throw new MediaFileInUseException($file->id, $references);
        }

        $this->media->forceDelete($file);
    }

    private function tenantId(): string
    {
        return $this->tenantContext->get()->getId();
    }
}
