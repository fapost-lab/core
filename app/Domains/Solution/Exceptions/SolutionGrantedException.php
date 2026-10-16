<?php

declare(strict_types=1);

namespace App\Domains\Solution\Exceptions;

use RuntimeException;

/**
 * Deactivation was asked for a Solution the operator grants the tenant (its plan's bundle or a running add-on): it
 * runs while the grant lasts, and only the operator ends it.
 */
final class SolutionGrantedException extends RuntimeException
{
    public static function forId(string $solutionId): self
    {
        return new self(sprintf('Solution "%s" is included in this tenant\'s plan or add-ons; it cannot be turned off here.', $solutionId));
    }
}
