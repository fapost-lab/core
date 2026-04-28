<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Media\Storage\StoragePathFactory;
use Carbon\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

final class StoragePathFactoryTest extends TestCase
{
    private StoragePathFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new StoragePathFactory();
    }

    public function test_path_matches_expected_structure(): void
    {
        $path = $this->factory->buildBlobPath(
            tenantId: 'tenant-abc',
            blobId: '01966b8c-1234-7abc-8def-000000000001',
            mimeType: 'image/jpeg',
            createdAt: Carbon::parse('2026-04-15 12:00:00'),
            originalFilename: 'photo.jpg',
        );

        $this->assertMatchesRegularExpression(
            '/^tenants\/[^\/]+\/[^\/]+\/\d{4}-\d{2}\/[0-9a-f]{2}\/[^\/]+\.jpg$/',
            $path,
        );
    }

    public function test_path_contains_correct_kind_segment(): void
    {
        $date = Carbon::parse('2026-04-01');

        $this->assertStringContainsString('/image/', $this->factory->buildBlobPath('t', 'b1', 'image/jpeg', $date));
        $this->assertStringContainsString('/video/', $this->factory->buildBlobPath('t', 'b2', 'video/mp4', $date));
        $this->assertStringContainsString('/audio/', $this->factory->buildBlobPath('t', 'b3', 'audio/mpeg', $date));
        $this->assertStringContainsString(
            '/document/',
            $this->factory->buildBlobPath('t', 'b4', 'application/pdf', $date)
        );
    }

    public function test_path_contains_yyyy_mm_bucket(): void
    {
        $path = $this->factory->buildBlobPath(
            tenantId: 'tenant-1',
            blobId: 'some-blob-id',
            mimeType: 'image/png',
            createdAt: Carbon::parse('2025-11-03'),
        );

        $this->assertStringContainsString('/2025-11/', $path);
    }

    public function test_determinism_same_inputs_produce_same_path(): void
    {
        $blobId = '01966b8c-0000-7000-8000-000000000099';
        $date   = Carbon::parse('2026-01-15 09:00:00');

        $first  = $this->factory->buildBlobPath('tenant-x', $blobId, 'image/jpeg', $date);
        $second = $this->factory->buildBlobPath('tenant-x', $blobId, 'image/jpeg', $date);

        $this->assertSame($first, $second);
    }

    public function test_different_blob_ids_produce_different_paths(): void
    {
        $date = Carbon::parse('2026-04-01');

        $path1 = $this->factory->buildBlobPath('tenant-x', 'blob-aaa', 'image/jpeg', $date);
        $path2 = $this->factory->buildBlobPath('tenant-x', 'blob-bbb', 'image/jpeg', $date);

        $this->assertNotSame($path1, $path2);
    }

    public function test_shard_distribution_is_uniform(): void
    {
        $factory = $this->factory;
        $date    = Carbon::parse('2026-04-01');
        $buckets = array_fill(0, 256, 0);
        $total   = 10_000;

        for ($i = 0; $i < $total; $i++) {
            $blobId = mb_strtolower((string)Str::ulid()->toRfc4122());
            $path   = $factory->buildBlobPath('tenant-x', $blobId, 'image/jpeg', $date);

            // Extract shard segment: tenants/{tenant}/image/{yyyy-mm}/{shard}/...
            $parts = explode('/', $path);
            $shard = $parts[4]; // index 4 = shard (0:tenants, 1:tenant, 2:kind, 3:yyyy-mm, 4:shard, 5:blob)

            $buckets[hexdec($shard)]++;
        }

        $expected = $total / 256;

        // Sanity check: no bucket left empty, no single bucket overwhelms (>2× avg).
        // Strict per-bucket ±N% would be flaky at this sample size due to natural Poisson variance.
        $this->assertGreaterThan(
            0,
            min($buckets),
            'At least one shard bucket received no entries — distribution is broken.',
        );
        $this->assertLessThanOrEqual(
            (int)($expected * 2.0),
            max($buckets),
            sprintf(
                'Max shard bucket (%d) exceeds 2× expected (%d) — distribution is severely skewed.',
                max($buckets),
                (int)$expected
            ),
        );
    }

    public function test_no_extension_when_mime_type_unknown(): void
    {
        $path = $this->factory->buildBlobPath(
            tenantId: 'tenant-1',
            blobId: 'blob-xyz',
            mimeType: 'application/x-unknown-type',
            createdAt: Carbon::parse('2026-04-01'),
        );

        // Should end with blobId, no trailing dot
        $this->assertStringEndsWith('blob-xyz', $path);
        $this->assertStringNotContainsString('blob-xyz.', $path);
    }
}
