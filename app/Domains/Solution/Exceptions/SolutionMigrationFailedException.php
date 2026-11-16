<?php

declare(strict_types=1);

namespace App\Domains\Solution\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A Solution's tenant migrations could not be applied in a tenant's schema. Nothing of that run was kept.
 */
final class SolutionMigrationFailedException extends RuntimeException
{
    public static function because(string $solutionId, string $migration, string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('Solution "%s": migration "%s" failed: %s', $solutionId, $migration, $reason), 0, $previous);
    }
}
