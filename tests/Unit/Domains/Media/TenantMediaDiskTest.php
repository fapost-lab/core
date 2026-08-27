<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Filesystem\FilesystemAdapter;
use InvalidArgumentException;
use Tests\TestCase;

final class TenantMediaDiskTest extends TestCase
{
    public function test_resolves_local_disk_for_default_config(): void
    {
        $tenant = new RuntimeTenant(id: 'tenant-id', schemaName: 'tenant-id', config: []);

        $disk = (new TenantMediaDisk())->resolve($tenant);

        $this->assertInstanceOf(FilesystemAdapter::class, $disk);
    }

    public function test_throws_for_unsupported_driver(): void
    {
        $tenant = new RuntimeTenant(
            id: 'tenant-id',
            schemaName: 'tenant-id',
            config: ['media' => ['storage' => ['driver' => 'oracle-cloud']]],
        );

        $this->expectException(InvalidArgumentException::class);

        (new TenantMediaDisk())->resolve($tenant);
    }

    public function test_disk_name_reads_driver_from_tenant_config(): void
    {
        $local = new RuntimeTenant(id: 't1', schemaName: 't1', config: []);
        $s3    = new RuntimeTenant(id: 't2', schemaName: 't2', config: ['media' => ['storage' => ['driver' => 's3']]]);

        $resolver = new TenantMediaDisk();

        $this->assertSame('local', $resolver->diskName($local));
        $this->assertSame('s3', $resolver->diskName($s3));
    }
}
