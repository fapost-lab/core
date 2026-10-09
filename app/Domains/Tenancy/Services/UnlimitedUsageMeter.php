<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\DTO\UsageDecision;
use Fapost\Foundation\Quota\DTO\UsageUnit;

/**
 * Default {@see UsageMeterInterface}: allows everything and records nothing. An installation
 * without an operator package behaves as if per-period limits did not exist.
 */
final class UnlimitedUsageMeter implements UsageMeterInterface
{
    public function consume(UsageUnit $unit): UsageDecision
    {
        return UsageDecision::allowed();
    }
}
