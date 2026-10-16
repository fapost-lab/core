<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Fapost\Foundation\Quota\Contracts\PeriodUsageInterface;
use Fapost\Foundation\Quota\DTO\PeriodUsageReport;

/**
 * Core's default for {@see PeriodUsageInterface}: no operator counts per-period use, so there is nothing to
 * report and the usage page shows no per-period rows.
 */
final class NoPeriodUsage implements PeriodUsageInterface
{
    public function reportFor(string $tenantId, string $locale): ?PeriodUsageReport
    {
        return null;
    }
}
