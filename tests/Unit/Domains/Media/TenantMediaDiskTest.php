<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Filesystem\AwsS3V3Adapter;
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
        $this->assertNotInstanceOf(AwsS3V3Adapter::class, $disk);
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

    public function test_tenant_without_own_storage_uses_the_installation_s3_disk(): void
    {
        config(['media.storage' => [
            'driver'                  => 's3',
            'bucket'                  => 'media',
            'key'                     => 'installation-key',
            'secret'                  => 'installation-secret',
            'region'                  => '',
            'endpoint'                => 'http://minio:9000',
            'use_path_style_endpoint' => 'true',
        ]]);

        $disk = (new TenantMediaDisk())->resolve(new RuntimeTenant(id: 't1', schemaName: 't1', config: []));

        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $this->assertSame('media', $disk->getConfig()['bucket']);
        $this->assertSame('http://minio:9000', $disk->getConfig()['endpoint']);
        $this->assertTrue($disk->getConfig()['use_path_style_endpoint']);
        $this->assertSame('us-east-1', $disk->getConfig()['region']);
        $this->assertSame('http://minio:9000/media/tenants/t1/x.jpg', (string)$disk->getClient()->getObjectUrl('media', 'tenants/t1/x.jpg'));
    }

    public function test_s3_move_does_not_read_the_source_acl(): void
    {
        config(['media.storage' => [
            'driver'   => 's3',
            'bucket'   => 'media',
            'key'      => 'k',
            'secret'   => 's',
            'endpoint' => 'http://minio:9000',
        ]]);

        $disk = (new TenantMediaDisk())->resolve(new RuntimeTenant(id: 't1', schemaName: 't1', config: []));

        $this->assertFalse($disk->getConfig()['retain_visibility']);
    }

    public function test_tenant_storage_wins_and_never_borrows_installation_credentials(): void
    {
        config(['media.storage' => [
            'driver' => 's3',
            'bucket' => 'media',
            'key'    => 'installation-key',
            'secret' => 'installation-secret',
        ]]);

        $tenant = new RuntimeTenant(
            id: 't1',
            schemaName: 't1',
            config: ['media' => ['storage' => ['driver' => 's3', 'bucket' => 'own-bucket', 'region' => 'eu-central-1']]],
        );

        $disk = (new TenantMediaDisk())->resolve($tenant);

        $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
        $this->assertSame('own-bucket', $disk->getConfig()['bucket']);
        $this->assertSame('', $disk->getConfig()['key']);
        $this->assertFalse($disk->getConfig()['use_path_style_endpoint']);
        $this->assertArrayNotHasKey('endpoint', $disk->getConfig());
    }

    public function test_s3_without_bucket_is_refused(): void
    {
        config(['media.storage' => ['driver' => 's3', 'bucket' => null]]);

        $this->expectException(InvalidArgumentException::class);

        (new TenantMediaDisk())->resolve(new RuntimeTenant(id: 't1', schemaName: 't1', config: []));
    }

    public function test_disk_name_is_the_installation_driver_or_tenant(): void
    {
        $installation = new RuntimeTenant(id: 't1', schemaName: 't1', config: []);
        $own          = new RuntimeTenant(id: 't2', schemaName: 't2', config: ['media' => ['storage' => ['driver' => 's3']]]);

        $resolver = new TenantMediaDisk();

        config(['media.storage.driver' => 'local']);
        $this->assertSame('local', $resolver->diskName($installation));

        config(['media.storage.driver' => 's3']);
        $this->assertSame('s3', $resolver->diskName($installation));
        $this->assertSame(TenantMediaDisk::TENANT_DISK, $resolver->diskName($own));
    }
}
