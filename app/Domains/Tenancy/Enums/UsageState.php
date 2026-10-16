<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Enums;

/**
 * Where a limit stands for the tenant, as the usage page shows it. Decided once, in
 * {@see \App\Domains\Tenancy\Services\TenantUsageOverview}; the client only draws it.
 */
enum UsageState: string
{
    /** No limit applies. */
    case Unlimited = 'unlimited';

    /** A limit is set, but nobody reported how much is used. */
    case NotReported = 'not_reported';

    /** Something failed while reading the limit or the use; the numbers are unknown, not "unlimited". */
    case Unavailable = 'unavailable';

    /** Below the near threshold. */
    case Ok = 'ok';

    /** At or above the near threshold, below the limit. */
    case Near = 'near';

    /** At or over the limit, a limit of zero included. */
    case Reached = 'reached';

    /** Whether the row asks for attention, for the dashboard widget. */
    public function needsAttention(): bool
    {
        return self::Near === $this || self::Reached === $this;
    }
}
