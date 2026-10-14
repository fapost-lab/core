<?php

declare(strict_types=1);

namespace App\Domains\Solution\Exceptions;

use RuntimeException;

/**
 * Activation was asked for a Solution that is not installed in this image or whose manifest is not valid.
 */
final class SolutionNotInstalledException extends RuntimeException
{
    public static function forId(string $solutionId): self
    {
        return new self(sprintf('Solution "%s" is not installed, or its manifest is not valid.', $solutionId));
    }
}
