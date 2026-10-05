<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use App\Domains\Tenancy\Support\TenancyResolutionMode;
use RuntimeException;

/**
 * Raised at boot when TENANCY_RESOLUTION names no known mode.
 */
final class InvalidTenancyResolutionModeException extends RuntimeException
{
    public static function forValue(mixed $value): self
    {
        $given = is_scalar($value) ? "[{$value}]" : '(' . get_debug_type($value) . ')';
        $known = implode(', ', array_column(TenancyResolutionMode::cases(), 'value'));

        return new self("Invalid TENANCY_RESOLUTION value {$given}: use one of {$known}.");
    }
}
