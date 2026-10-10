<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Media;

use App\Domains\Media\Models\MediaBlob;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTestCase;

final class RecountBlobSizesCommandTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = 'tenants/' . self::TENANT_ID . '/media/_recount_' . uniqid();
        File::ensureDirectoryExists(storage_path('app/' . $this->directory));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/' . $this->directory));

        parent::tearDown();
    }

    public function test_dry_run_reports_the_difference_and_changes_nothing(): void
    {
        $wrong = $this->blob('wrong', 'abcdefgh', 3);

        $this->artisan('media:recount-blob-sizes', ['--dry-run' => true])
            ->expectsOutputToContain('3 -> 8 bytes (dry run)')
            ->assertSuccessful();

        $this->assertSame(3, (int) $wrong->fresh()->size);
    }

    public function test_it_corrects_the_size_and_a_second_run_finds_nothing_to_do(): void
    {
        $wrong   = $this->blob('wrong', 'abcdefgh', 3);
        $correct = $this->blob('correct', 'xyz', 3);

        $this->artisan('media:recount-blob-sizes', ['--tenant' => 'main'])
            ->expectsOutputToContain('1 corrected')
            ->assertSuccessful();

        $this->assertSame(8, (int) $wrong->fresh()->size);
        $this->assertSame(3, (int) $correct->fresh()->size);

        $this->artisan('media:recount-blob-sizes')
            ->expectsOutputToContain('0 corrected')
            ->assertSuccessful();
    }

    public function test_a_blob_without_an_object_is_skipped(): void
    {
        $blob = $this->blob('gone', null, 5);

        $this->artisan('media:recount-blob-sizes')
            ->expectsOutputToContain('has no stored object, skipped')
            ->assertSuccessful();

        $this->assertSame(5, (int) $blob->fresh()->size);
    }

    public function test_an_unknown_tenant_fails(): void
    {
        $this->artisan('media:recount-blob-sizes', ['--tenant' => 'nope'])->assertFailed();
    }

    private function blob(string $name, ?string $content, int $recordedSize): MediaBlob
    {
        $path = $this->directory . '/' . $name . '.bin';

        if (null !== $content) {
            File::put(storage_path('app/' . $path), $content);
        }

        return MediaBlob::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'content_hash' => hash('sha256', $name),
            'storage_path' => $path,
            'storage_disk' => 'local',
            'size'         => $recordedSize,
            'mime_type'    => 'application/octet-stream',
        ]);
    }
}
