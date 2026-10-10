<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Throwable;

/**
 * Corrects `media_blobs.size` of the current tenant to the size of the stored object.
 *
 * Blobs saved from a stream before the uploader measured the buffer carry a size counted in
 * characters, not bytes; the storage limit would count them short. Reading the object is the only
 * way to know the truth, so this is a command's job and not a migration's. It is idempotent.
 */
final readonly class BlobSizeRecounter
{
    public function __construct(
        private TenantContextInterface $context,
        private TenantMediaDisk $tenantMediaDisk,
    ) {
    }

    /**
     * @return array{checked: int, changed: list<array{id: string, path: string, stored: int, actual: int}>, missing: list<string>}
     */
    public function recount(bool $dryRun): array
    {
        $tenant  = $this->context->get();
        $disk    = $this->tenantMediaDisk->resolve($tenant);
        $checked = 0;
        $changed = [];
        $missing = [];

        MediaBlob::query()
            ->where('tenant_id', $tenant->getId())
            ->chunkById(200, function ($blobs) use ($disk, $dryRun, &$checked, &$changed, &$missing): void {
                foreach ($blobs as $blob) {
                    ++$checked;

                    try {
                        $actual = $disk->size($blob->storage_path);
                    } catch (Throwable) {
                        $missing[] = $blob->id;

                        continue;
                    }

                    if ($actual === (int) $blob->size) {
                        continue;
                    }

                    $changed[] = [
                        'id'     => $blob->id,
                        'path'   => $blob->storage_path,
                        'stored' => (int) $blob->size,
                        'actual' => $actual,
                    ];

                    if (! $dryRun) {
                        $blob->forceFill(['size' => $actual])->save();
                    }
                }
            });

        return ['checked' => $checked, 'changed' => $changed, 'missing' => $missing];
    }
}
