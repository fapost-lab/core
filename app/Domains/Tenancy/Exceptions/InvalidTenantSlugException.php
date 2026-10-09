<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use App\Domains\Tenancy\ValueObjects\SlugRejection;
use RuntimeException;

/**
 * Raised when a tenant slug is malformed or claims a name the platform owns.
 */
final class InvalidTenantSlugException extends RuntimeException
{
    /**
     * True when the slug is well-formed but names something the platform owns, so a caller can tell
     * it from a malformed one without parsing the message.
     */
    public readonly bool $reservedByPlatform;

    /**
     * @param  SlugRejection  $reason  Which rule refused the slug, as a code a caller can map to its own text.
     */
    public function __construct(
        string $message,
        public readonly SlugRejection $reason = SlugRejection::Malformed,
    ) {
        parent::__construct($message);

        $this->reservedByPlatform = SlugRejection::Reserved === $reason;
    }

    public static function malformed(string $slug): self
    {
        return new self(
            "Tenant slug [{$slug}] is not a valid DNS label: use 1-63 characters of a-z, 0-9 and '-', "
            . "not starting or ending with '-'.",
            SlugRejection::Malformed,
        );
    }

    public static function tooLong(string $slug, int $maxLength, int $maxSchemaBytes): self
    {
        return new self(
            "Tenant slug [{$slug}] is too long: use at most {$maxLength} characters, "
            . "so its schema name fits PostgreSQL's {$maxSchemaBytes}-byte identifier limit.",
            SlugRejection::TooLong,
        );
    }

    public static function consecutiveHyphens(string $slug): self
    {
        return new self(
            "Tenant slug [{$slug}] contains '--': its schema name would collide with the slug that has a single hyphen.",
            SlugRejection::ConsecutiveHyphens,
        );
    }

    public static function reserved(string $slug): self
    {
        return new self("Tenant slug [{$slug}] is reserved by the platform.", SlugRejection::Reserved);
    }

    public static function punycodePrefix(string $slug): self
    {
        return new self(
            "Tenant slug [{$slug}] uses the reserved 'xn--' prefix, which encodes internationalized names.",
            SlugRejection::PunycodePrefix,
        );
    }
}
