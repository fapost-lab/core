<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Statistics\NodeUsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

interface NodeUsageStatisticsInterface
{
    /**
     * Node and action usage for the tenant of the current tenant context, whose schema the default connection points at.
     *
     * `$runtimeSince` bounds the runtime slice only (up to now); the static slice always reflects the published versions
     * that can still run. With `$assistants`, only the flows whose draft belongs to one of those assistants and the runs
     * of their sessions are counted.
     *
     * @param  Builder<Assistant>|null  $assistants  the assistants to count for; `null` is the whole tenant
     */
    public function reportForCurrentTenant(CarbonImmutable $runtimeSince, ?Builder $assistants = null): NodeUsageReport;
}
