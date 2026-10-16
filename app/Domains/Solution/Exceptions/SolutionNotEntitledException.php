<?php

declare(strict_types=1);

namespace App\Domains\Solution\Exceptions;

use RuntimeException;

/**
 * Activation was asked for a Solution the operator does not entitle the tenant to run.
 */
final class SolutionNotEntitledException extends RuntimeException
{
    public static function forId(string $solutionId): self
    {
        return new self(sprintf('Solution "%s" is not included in what this tenant is entitled to.', $solutionId));
    }
}
