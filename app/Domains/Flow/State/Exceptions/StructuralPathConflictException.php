<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Exceptions;

use RuntimeException;

/**
 * Thrown when a write would violate the JSONB structural shape — for example,
 * writing {@code contact.profile.name} when {@code contact.profile} is already
 * a leaf scalar, or vice-versa. Distinct from validation-time conflicts
 * (which the flow definition validator catches): this fires at runtime when
 * an assign operation produces a previously incompatible value type.
 */
final class StructuralPathConflictException extends RuntimeException
{
    public static function leafVsGroup(string $path): self
    {
        return new self(sprintf(
            "Path '%s' already exists as a leaf value but the write expects a nested object (or vice-versa).",
            $path,
        ));
    }
}
