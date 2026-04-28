<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Media;

use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use FAPost\Foundation\Media\Enums\MediaKind;
use Illuminate\Http\UploadedFile;
use Tests\Feature\FeatureTestCase;

final class MediaUploaderTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(
                id: '00000000-0000-0000-0000-000000000001',
                schemaName: 'main',
            )
        );
    }

    public function test_uploads_file_creates_blob_and_media_file(): void
    {
        $upload = UploadedFile::fake()->createWithContent('hello.txt', 'hello world');

        $media = $this->app->make(MediaUploaderInterface::class)->uploadFromUploadedFile(
            file: $upload,
            folder: null,
            name: 'hello.txt',
            source: MediaSource::Upload,
        );

        $this->assertInstanceOf(MediaFile::class, $media);
        $this->assertSame('hello.txt', $media->name);
        $this->assertSame(MediaKind::Document, $media->kind);
        $this->assertSame(MediaSource::Upload, $media->source);
        $this->assertSame(1, MediaBlob::query()->count());
        $this->assertSame(1, MediaFile::query()->count());
        $this->assertSame(hash('sha256', 'hello world'), $media->blob->content_hash);

        // Verify new sharded path schema: tenants/{id}/document/{yyyy-mm}/{shard}/...
        $this->assertMatchesRegularExpression(
            '/^tenants\/[^\/]+\/document\/\d{4}-\d{2}\/[0-9a-f]{2}\/[^\/]+\.txt$/',
            $media->blob->storage_path,
        );
    }

    public function test_uploading_same_content_twice_deduplicates_to_one_blob(): void
    {
        $uploader = $this->app->make(MediaUploaderInterface::class);

        $first = $uploader->uploadFromUploadedFile(
            UploadedFile::fake()->createWithContent('a.txt', 'same content'),
            null,
            'a.txt',
            MediaSource::Upload,
        );

        $second = $uploader->uploadFromUploadedFile(
            UploadedFile::fake()->createWithContent('b.txt', 'same content'),
            null,
            'b.txt',
            MediaSource::Upload,
        );

        $this->assertSame(1, MediaBlob::query()->count());
        $this->assertSame(2, MediaFile::query()->count());
        $this->assertSame($first->blob_id, $second->blob_id);
        $this->assertNotSame($first->id, $second->id);
    }
}
