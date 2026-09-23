<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Statistics\NodeUsageReport;
use Carbon\CarbonImmutable;

interface NodeUsageStatisticsInterface
{
    /**
     * Node usage for the tenant the default connection currently points at.
     *
     * `$runtimeSince` bounds the runtime slice only; the static slice always
     * reflects the current active definitions.
     */
    public function reportForCurrentTenant(CarbonImmutable $runtimeSince): NodeUsageReport;
}
