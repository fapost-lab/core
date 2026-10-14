<?php

declare(strict_types=1);

namespace App\Domains\Solution\Enums;

/**
 * What a tenant has chosen for a Solution. A string column, not a flag, so a later state (degraded)
 * needs no DDL. No row means the Solution was never activated, which reads as inactive.
 */
enum SolutionActivationStatus: string
{
    case Active   = 'active';
    case Inactive = 'inactive';
}
