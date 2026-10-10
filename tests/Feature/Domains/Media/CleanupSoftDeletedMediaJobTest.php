<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Media;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Jobs\Media\CleanupSoftDeletedMediaJob;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

final class CleanupSoftDeletedMediaJobTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: '00000000-0000-0000-0000-000000000001', schemaName: 'main'),
        );
    }

    public function test_it_removes_old_trashed_files_and_frees_their_blob_and_object(): void
    {
        $file = $this->upload('old.txt', 'old-' . uniqid());
        $path = storage_path('app/' . $file->blob->storage_path);
        $file->delete();
        MediaFile::withTrashed()->whereKey($file->id)->update(['deleted_at' => CarbonImmutable::now()->subDays(40)]);

        $recent = $this->upload('recent.txt', 'recent-' . uniqid());
        $recent->delete();

        $this->app->call([new CleanupSoftDeletedMediaJob(), 'handle']);

        $this->assertNull(MediaFile::withTrashed()->find($file->id));
        $this->assertNull(MediaBlob::query()->find($file->blob_id));
        $this->assertFileDoesNotExist($path);
        $this->assertNotNull(MediaFile::withTrashed()->find($recent->id), 'A file inside the retention window stays.');
        $this->assertNotNull(MediaBlob::query()->find($recent->blob_id));
    }

    public function test_a_blob_a_parallel_upload_just_reused_survives_force_delete(): void
    {
        $file = $this->upload('race.txt', 'race-' . uniqid());
        $path = storage_path('app/' . $file->blob->storage_path);
        $file->delete();

        // A second file attaches to the blob after the "no other file" check, before the blob delete.
        MediaBlob::deleting(function (MediaBlob $blob) use ($file): void {
            MediaFile::query()->create([
                'tenant_id' => $blob->tenant_id,
                'blob_id'   => $blob->id,
                'name'      => 'racer.txt',
                'kind'      => $file->kind,
                'metadata'  => [],
                'source'    => MediaSource::Upload,
            ]);
        });

        $this->app->make(MediaServiceInterface::class)->forceDelete($file);

        $this->assertNotNull(MediaBlob::query()->find($file->blob_id));
        $this->assertFileExists($path);
    }

    public function test_other_database_errors_are_not_swallowed(): void
    {
        $file = $this->upload('boom.txt', 'boom-' . uniqid());
        $file->delete();

        MediaBlob::deleting(static function (): void {
            throw new QueryException('main', 'delete from media_blobs', [], new RuntimeException('connection lost'));
        });

        $this->expectException(QueryException::class);

        $this->app->make(MediaServiceInterface::class)->forceDelete($file);
    }

    public function test_a_failing_disk_delete_is_logged_and_does_not_fail_the_delete(): void
    {
        Log::spy();
        $file = $this->upload('stuck.txt', 'stuck-' . uniqid());
        $file->delete();

        // Replace the object by a non-empty directory so the disk cannot delete it.
        $path = storage_path('app/' . $file->blob->storage_path);
        unlink($path);
        mkdir($path);
        file_put_contents($path . '/keep', 'x');

        try {
            $this->app->make(MediaServiceInterface::class)->forceDelete($file);
        } finally {
            unlink($path . '/keep');
            rmdir($path);
        }

        $this->assertNull(MediaFile::withTrashed()->find($file->id));
        $this->assertNull(MediaBlob::query()->find($file->blob_id));
        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => 'media.blob.object_delete_failed' === $message,
        )->once();
    }

    private function upload(string $name, string $content): MediaFile
    {
        return $this->app->make(MediaUploaderInterface::class)->uploadFromUploadedFile(
            file: UploadedFile::fake()->createWithContent($name, $content),
            folder: null,
            name: $name,
            source: MediaSource::Upload,
        )->load('blob');
    }
}
