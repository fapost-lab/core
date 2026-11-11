<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Models\ActivityEntry;
use Carbon\CarbonImmutable;

/**
 * Deletes the current tenant's audit entries older than a cutoff, in batches by the `created_at` index, so a large
 * backlog never holds one long lock.
 */
final readonly class ActivityLogPruner
{
    private const int BATCH = 1000;

    /**
     * @return int how many entries were deleted, or would be on a dry run
     */
    public function prune(CarbonImmutable $cutoff, bool $dryRun = false): int
    {
        if ($dryRun) {
            return ActivityEntry::query()->where('created_at', '<', $cutoff)->count();
        }

        $deleted = 0;

        do {
            $ids = ActivityEntry::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('created_at')
                ->limit(self::BATCH)
                ->pluck('id')
                ->all();

            if ([] === $ids) {
                break;
            }

            $deleted += ActivityEntry::query()->whereKey($ids)->delete();
        } while (self::BATCH === count($ids));

        return $deleted;
    }
}
