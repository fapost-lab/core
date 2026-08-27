<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when a tenant slug is malformed or claims a name the platform owns.
 */
final class InvalidTenantSlugException extends RuntimeException
{
    public static function malformed(string $slug): self
    {
        return new self(
            "Tenant slug [{$slug}] is not a valid DNS label: use 1-63 characters of a-z, 0-9 and '-', "
            . "not starting or ending with '-'."
        );
    }

    public static function reserved(string $slug): self
    {
        return new self("Tenant slug [{$slug}] is reserved by the platform.");
    }

    public static function punycodePrefix(string $slug): self
    {
        return new self(
            "Tenant slug [{$slug}] uses the reserved 'xn--' prefix, which encodes internationalized names."
        );
    }
}
