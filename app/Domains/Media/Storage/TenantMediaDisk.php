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
 * required for Octane safety, since registering tenant-specific disks at boot time
 * would leak between requests.
 *
 * Configuration is read from the tenant config namespace `media.storage`. Defaults to
 * a private local disk under `storage/app/tenants/{id}/media` when nothing is configured.
 */
final class TenantMediaDisk
{
    public function resolve(TenantInterface $tenant): Filesystem
    {
        $config = $tenant->getConfig('media.storage', []);

        if ( ! is_array($config)) {
            $config = [];
        }

        $driver = is_string($config['driver'] ?? null) ? $config['driver'] : 'local';

        // The blob path stored on `media_blobs.storage_path` already carries the full
        // `tenants/{id}/media/{ulid}.{ext}` prefix (see StoragePathFactory). The disk
        // root must therefore stay above that prefix — `storage/app` for local,
        // bucket-root for s3 — so we never double-nest the tenant segment.
        return match ($driver) {
            'local' => Storage::build([
                'driver'     => 'local',
                'root'       => storage_path('app'),
                'visibility' => 'private',
                'throw'      => true,
            ]),
            's3' => Storage::build([
                'driver' => 's3',
                'bucket' => (string)($config['bucket'] ?? ''),
                'root'   => (string)($config['root'] ?? ''),
                'key'    => (string)($config['key'] ?? ''),
                'secret' => (string)($config['secret'] ?? ''),
                'region' => (string)($config['region'] ?? ''),
                'throw'  => true,
            ]),
            default => throw new InvalidArgumentException(
                sprintf('Unsupported tenant media disk driver [%s].', $driver)
            ),
        };
    }

    /**
     * Disk name as recorded on media_blobs.storage_disk for diagnostic purposes.
     */
    public function diskName(TenantInterface $tenant): string
    {
        $config = $tenant->getConfig('media.storage', []);
        $driver = is_array($config) && is_string($config['driver'] ?? null) ? $config['driver'] : 'local';

        return $driver;
    }
}
