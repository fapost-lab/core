<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Support;

use App\Domains\Tenancy\Exceptions\InvalidTenancyResolutionModeException;

/**
 * How a web request is mapped to a tenant ({@code config('tenancy.resolution')}).
 */
enum TenancyResolutionMode: string
{
    /** One tenant per deployment, taken from TENANT_SLUG; the request host is not read. */
    case Single = 'single';

    /** The tenant is named by the request host: `<slug>.<base_domain>`. */
    case Host = 'host';

    /**
     * @throws InvalidTenancyResolutionModeException when the configured value names no mode.
     */
    public static function fromConfig(): self
    {
        $value = config('tenancy.resolution', self::Single->value);

        if (is_string($value) && null !== ($mode = self::tryFrom($value))) {
            return $mode;
        }

        throw InvalidTenancyResolutionModeException::forValue($value);
    }
}
