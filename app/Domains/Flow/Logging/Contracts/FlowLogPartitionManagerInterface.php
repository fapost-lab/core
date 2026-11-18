<?php

declare(strict_types=1);

namespace App\Domains\Flow\Logging\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

interface FlowLogPartitionManagerInterface
{
    public function ensureMonthlyPartition(CarbonImmutable $month): void;

    /**
     * Monthly partitions of flow_logs in the current schema only; the partitions
     * of other tenant schemas are never listed.
     *
     * @return Collection<int, string>
     */
    public function listMonthlyPartitions(): Collection;

    /**
     * Drops one monthly partition of the current schema only; it never reaches
     * another tenant's schema. Fails with an exception when the table is locked
     * for longer than a few seconds, so the caller's next run retries.
     */
    public function dropPartition(string $partitionName): void;
}
