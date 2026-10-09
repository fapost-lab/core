<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when a web request starts in `host` tenancy mode with a configuration that cannot work there.
 */
final class UnsupportedHostModeConfigurationException extends RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    public static function forProblems(array $problems): self
    {
        return new self('TENANCY_RESOLUTION=host cannot run with this configuration: ' . implode(' ', $problems));
    }
}
