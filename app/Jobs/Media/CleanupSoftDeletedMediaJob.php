<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFileReference;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Hard-deletes media files that have been soft-deleted longer than the retention window
 * and have no remaining references. When a blob loses its last referencing file the blob
 * row and its underlying storage object are dropped as well.
 *
 * Iterates active tenants so a single scheduled invocation cleans up the entire fleet.
 */
final class CleanupSoftDeletedMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $retentionDays = 30;

    public function __construct(?int $retentionDays = null)
    {
        $this->onQueue('messaging.system');

        if (null !== $retentionDays) {
            $this->retentionDays = $retentionDays;
        }
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        MediaServiceInterface $mediaService,
    ): void {
        $threshold = CarbonImmutable::now()->subDays($this->retentionDays);

        foreach ($tenants->findAllActive() as $tenant) {
            $switcher->runForTenant($tenant, function () use ($threshold, $mediaService): void {
                MediaFile::query()
                    ->onlyTrashed()
                    ->where('deleted_at', '<', $threshold)
                    ->chunkById(100, function ($files) use ($mediaService): void {
                        foreach ($files as $file) {
                            $hasRefs = MediaFileReference::query()
                                ->where('media_file_id', $file->id)
                                ->exists();

                            if ($hasRefs) {
                                continue;
                            }

                            $mediaService->forceDelete($file);
                        }
                    });
            });
        }
    }
}
