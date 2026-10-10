<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Media;

use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Services\MediaStorageGate;
use App\Domains\Media\Services\StoredMediaBytes;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\Enums\LimitKind;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantLimits;

final class MediaStorageGateTest extends FeatureTestCase
{
    private string $tenantId;

    private FakeTenantLimits $limits;

    protected function setUp(): void
    {
        parent::setUp();

        // A tenant of its own, so the files this test writes to the local disk are easy to find and remove.
        $this->tenantId = '00000000-0000-4000-8000-' . mb_substr(md5(uniqid('', true)), 0, 12);

        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: $this->tenantId, schemaName: 'main'),
        );

        $this->limits = new FakeTenantLimits();
        $this->app->instance(TenantLimitsInterface::class, $this->limits);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/tenants/' . $this->tenantId));

        parent::tearDown();
    }

    public function test_the_media_storage_key_is_registered_as_a_byte_limit(): void
    {
        $definition = $this->app->make(LimitRegistryInterface::class)->find('media_storage');

        $this->assertNotNull($definition);
        $this->assertSame('Media storage', $definition->label);
        $this->assertSame('bytes', $definition->unit);
        $this->assertSame(LimitKind::Bytes, $definition->kind);
    }

    public function test_without_a_limit_nothing_is_counted(): void
    {
        DB::enableQueryLog();

        $this->upload('a.txt', 'hello');

        $aggregates = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_contains(mb_strtolower($query['query']), 'sum('),
        );

        $this->assertSame([], $aggregates);
        $this->assertSame(1, MediaBlob::query()->count());
    }

    public function test_content_that_fits_is_stored_up_to_the_limit_exactly(): void
    {
        $this->limits->limits['media_storage'] = 10;

        $this->upload('a.txt', '12345');
        $this->upload('b.txt', 'abcde');

        $this->assertSame(10, $this->app->make(StoredMediaBytes::class)->current());
        $this->assertSame(2, MediaFile::query()->count());
    }

    public function test_content_over_the_limit_is_refused_and_leaves_nothing_behind(): void
    {
        $this->limits->limits['media_storage'] = 10;
        $this->upload('a.txt', '12345');

        try {
            $this->upload('b.txt', 'abcdef');
            $this->fail('Expected StorageLimitReachedException.');
        } catch (StorageLimitReachedException $exception) {
            $this->assertSame(10, $exception->limit);
            $this->assertSame(5, $exception->used);
            $this->assertSame(6, $exception->incoming);
        }

        $this->assertSame(1, MediaBlob::query()->count());
        $this->assertSame(1, MediaFile::query()->count());
        $this->assertSame(
            [$this->storedObjectOf(MediaBlob::query()->firstOrFail())],
            $this->filesOnDisk(),
            'Only the first blob is on the tenant disk; the refused bytes, temp path included, were never written.',
        );
    }

    public function test_a_limit_of_zero_stores_nothing_new(): void
    {
        $this->limits->limits['media_storage'] = 0;

        $this->expectException(StorageLimitReachedException::class);

        try {
            $this->upload('a.txt', 'x');
        } finally {
            $this->assertSame([], $this->filesOnDisk());
        }
    }

    public function test_content_the_tenant_already_stores_is_allowed_over_the_limit(): void
    {
        $this->upload('a.txt', '1234567890');
        $this->limits->limits['media_storage'] = 5;

        $copy = $this->upload('copy.txt', '1234567890');

        $this->assertSame(1, MediaBlob::query()->count());
        $this->assertSame(2, MediaFile::query()->count());
        $this->assertNotNull($copy->blob_id);
    }

    public function test_trashed_files_still_count_until_deleted_permanently(): void
    {
        $this->limits->limits['media_storage'] = 10;
        $this->upload('a.txt', '1234567890')->delete();

        $this->expectException(StorageLimitReachedException::class);

        $this->upload('b.txt', 'x');
    }

    public function test_a_staff_upload_fails_closed_when_the_operator_errors(): void
    {
        $this->limits->failure = new RuntimeException('operator is down');

        try {
            $this->upload('a.txt', 'hello');
            $this->fail('Expected the operator failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('operator is down', $exception->getMessage());
        }

        $this->assertSame(0, MediaBlob::query()->count());
    }

    public function test_inbound_media_fails_open_when_the_operator_errors(): void
    {
        $this->limits->failure = new RuntimeException('operator is down');
        $reported              = false;
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)
            ->reportable(function (RuntimeException $e) use (&$reported): void {
                $reported = 'operator is down' === $e->getMessage();
            });

        foreach ([MediaSource::Conversation, MediaSource::InputNode] as $index => $source) {
            $this->app->make(MediaUploaderInterface::class)->storeFromStream(
                stream: Utils::streamFor('inbound-' . $index),
                mimeType: 'text/plain',
                originalFilename: 'in.txt',
                folder: null,
                source: $source,
            );
        }

        $this->assertSame(2, MediaBlob::query()->count());
        $this->assertTrue($reported);
    }

    public function test_inbound_media_over_the_limit_is_refused(): void
    {
        $this->limits->limits['media_storage'] = 3;

        $this->expectException(StorageLimitReachedException::class);

        $this->app->make(MediaUploaderInterface::class)->storeFromStream(
            stream: Utils::streamFor('four'),
            mimeType: 'text/plain',
            originalFilename: 'in.txt',
            folder: null,
            source: MediaSource::Conversation,
        );
    }

    public function test_a_refusal_is_logged_once_with_the_numbers(): void
    {
        Log::spy();
        $this->limits->limits['media_storage'] = 2;

        try {
            $this->upload('a.txt', 'abc');
        } catch (StorageLimitReachedException) {
        }

        Log::shouldHaveReceived('info')->once()->with('quota.storage.refused', [
            'tenant_id' => $this->tenantId,
            'key'       => 'media_storage',
            'limit'     => 2,
            'used'      => 0,
            'incoming'  => 3,
            'source'    => 'upload',
        ]);
    }

    public function test_the_gate_asks_the_registered_byte_key(): void
    {
        $gate = $this->app->make(MediaStorageGate::class);

        $gate->assertFits(1_000_000, MediaSource::Upload);

        $this->assertSame('media_storage', MediaStorageGate::LIMIT_KEY);
    }

    private function upload(string $name, string $content): MediaFile
    {
        return $this->app->make(MediaUploaderInterface::class)->uploadFromUploadedFile(
            file: UploadedFile::fake()->createWithContent($name, $content),
            folder: null,
            name: $name,
            source: MediaSource::Upload,
        );
    }

    /**
     * @return list<string>
     */
    private function filesOnDisk(): array
    {
        $root = storage_path('app/tenants/' . $this->tenantId);

        if (! is_dir($root)) {
            return [];
        }

        $paths = [];

        foreach (File::allFiles($root) as $file) {
            $paths[] = 'tenants/' . $this->tenantId . '/' . str_replace('\\', '/', $file->getRelativePathname());
        }

        sort($paths);

        return $paths;
    }

    private function storedObjectOf(MediaBlob $blob): string
    {
        return $blob->storage_path;
    }
}
