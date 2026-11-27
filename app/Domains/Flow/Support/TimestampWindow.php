<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

use Carbon\CarbonImmutable;

/**
 * The bounds of a time window over a `timestamptz` column, as an operator screen's read binds them.
 *
 * On PostgreSQL a bound without a zone is read in the session's TimeZone, which an installation may set to something
 * other than UTC: the window would move and drop the newest rows. So there each bound carries its offset. SQLite stores
 * the column in the app timezone (UTC) and takes the dates as they are.
 */
final class TimestampWindow
{
    /**
     * @return array{0: CarbonImmutable|string, 1: CarbonImmutable|string}
     */
    public static function bounds(string $driver, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return 'pgsql' === $driver
            ? [$start->format('Y-m-d H:i:sP'), $end->format('Y-m-d H:i:sP')]
            : [$start, $end];
    }
}
