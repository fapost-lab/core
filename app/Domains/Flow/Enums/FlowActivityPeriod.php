<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

use Carbon\CarbonImmutable;

/**
 * How far back an operator screen looks into flow activity.
 *
 * Every read of `flow_logs` is bounded by one of these: the table is range-partitioned by month, and a query without a
 * lower bound on `created_at` (or a page count over it) touches every partition. The longest period matches the log
 * retention, so nothing a period hides is still meant to be kept.
 */
enum FlowActivityPeriod: string
{
    case LastHour  = '1h';
    case LastDay   = '24h';
    case LastWeek  = '7d';
    case LastMonth = '30d';

    public static function default(): self
    {
        return self::LastDay;
    }

    /**
     * The start of the window that ends now.
     */
    public function since(CarbonImmutable $now): CarbonImmutable
    {
        return match ($this) {
            self::LastHour  => $now->subHour(),
            self::LastDay   => $now->subDay(),
            self::LastWeek  => $now->subWeek(),
            self::LastMonth => $now->subDays(30),
        };
    }
}
