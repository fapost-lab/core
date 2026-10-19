<?php

declare(strict_types=1);

namespace App\Domains\Media\Storage;

use App\Domains\Tenancy\Contracts\TenantInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Resolves a per-tenant filesystem disk for media storage.
 *
 * Built through {@see Storage::build()} on every call so no global config is mutated —
 * registering tenant-specific disks at boot time would leak between tenants on a
 * long-lived worker.
 *
 * A tenant's own `media.storage` config wins when it names a driver; otherwise the
 * installation's disk (`config('media.storage')`, set from `.env`) is used. Both have the
 * same shape. The installation disk defaults to a private local disk under `storage/app`.
 *
 * Switching the installation disk does not move stored objects: blobs are read from the
 * disk resolved now, so a populated installation copies `tenants/` to the new disk first.
 */
final class TenantMediaDisk
{
    /** Recorded on media_blobs.storage_disk when the installation disk holds the blob. */
    public const string INSTALLATION_DISK = 'media';

    public function resolve(TenantInterface $tenant): Filesystem
    {
        $config = $this->tenantConfig($tenant) ?? $this->installationConfig();
        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'local';

        // The blob path stored on `media_blobs.storage_path` already carries the full
        // `tenants/{id}/…` prefix (see StoragePathFactory). The disk root must therefore
        // stay above that prefix — `storage/app` for local, bucket-root for s3 — so we
        // never double-nest the tenant segment, and the same keys work on either disk.
        return match ($driver) {
            'local' => Storage::build([
                'driver'     => 'local',
                'root'       => storage_path('app'),
                'visibility' => 'private',
                'throw'      => true,
            ]),
            's3'    => Storage::build($this->s3Config($config)),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported tenant media disk driver [%s].', $driver)
            ),
        };
    }

    /**
     * Disk name as recorded on media_blobs.storage_disk: the tenant's own driver when the
     * tenant overrides storage, {@see self::INSTALLATION_DISK} otherwise.
     */
    public function diskName(TenantInterface $tenant): string
    {
        $config = $this->tenantConfig($tenant);

        if (null === $config) {
            return self::INSTALLATION_DISK;
        }

        return is_string($config['driver'] ?? null) ? $config['driver'] : 'local';
    }

    /**
     * The tenant's own storage config, or null when it does not name a driver. A partial
     * override is not merged with the installation's, so a tenant bucket never borrows the
     * installation's credentials.
     *
     * @return array<string, mixed>|null
     */
    private function tenantConfig(TenantInterface $tenant): ?array
    {
        $config = $tenant->getConfig('media.storage');

        return is_array($config) && is_string($config['driver'] ?? null) ? $config : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function installationConfig(): array
    {
        $config = config('media.storage', []);

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function s3Config(array $config): array
    {
        $bucket = (string)($config['bucket'] ?? '');

        if ('' === $bucket) {
            throw new InvalidArgumentException('The s3 media disk needs a bucket.');
        }

        $disk = [
            'driver' => 's3',
            'bucket' => $bucket,
            'root'   => (string)($config['root'] ?? ''),
            'key'    => (string)($config['key'] ?? ''),
            'secret' => (string)($config['secret'] ?? ''),
            // The AWS SDK refuses an empty region; S3-compatible servers such as MinIO accept us-east-1.
            'region'     => '' !== (string)($config['region'] ?? '') ? (string)$config['region'] : 'us-east-1',
            'visibility' => 'private',
            'throw'      => true,
        ];

        $endpoint = (string)($config['endpoint'] ?? '');

        if ('' !== $endpoint) {
            $disk['endpoint'] = $endpoint;
        }

        $disk['use_path_style_endpoint'] = filter_var(
            $config['use_path_style_endpoint'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        return $disk;
    }
}
