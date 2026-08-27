<?php

declare(strict_types=1);

namespace App\Domains\Flow\Logging\Contracts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

interface FlowLogPartitionManagerInterface
{
    public function ensureMonthlyPartition(CarbonImmutable $month): void;

    /**
     * @return Collection<int, string>
     */
    public function listMonthlyPartitions(): Collection;

    public function dropPartition(string $partitionName): void;
}
