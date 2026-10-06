<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

/**
 * Raised when a tenant slug is malformed or claims a name the platform owns.
 */
final class InvalidTenantSlugException extends RuntimeException
{
    /**
     * @param  bool  $reservedByPlatform  True when the slug is well-formed but names something the platform owns,
     *                                    so a caller can tell it from a malformed one without parsing the message.
     */
    public function __construct(
        string $message,
        public readonly bool $reservedByPlatform = false,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $slug): self
    {
        return new self(
            "Tenant slug [{$slug}] is not a valid DNS label: use 1-63 characters of a-z, 0-9 and '-', "
            . "not starting or ending with '-'."
        );
    }

    public static function tooLong(string $slug, int $maxLength, int $maxSchemaBytes): self
    {
        return new self(
            "Tenant slug [{$slug}] is too long: use at most {$maxLength} characters, "
            . "so its schema name fits PostgreSQL's {$maxSchemaBytes}-byte identifier limit."
        );
    }

    public static function consecutiveHyphens(string $slug): self
    {
        return new self(
            "Tenant slug [{$slug}] contains '--': its schema name would collide with the slug that has a single hyphen."
        );
    }

    public static function reserved(string $slug): self
    {
        return new self("Tenant slug [{$slug}] is reserved by the platform.", reservedByPlatform: true);
    }

    public static function punycodePrefix(string $slug): self
    {
        return new self(
            "Tenant slug [{$slug}] uses the reserved 'xn--' prefix, which encodes internationalized names."
        );
    }
}
