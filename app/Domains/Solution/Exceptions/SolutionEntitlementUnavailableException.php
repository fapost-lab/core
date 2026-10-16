<?php

declare(strict_types=1);

namespace App\Domains\Solution\Exceptions;

use RuntimeException;

/**
 * Activation was asked while the operator's entitlement answer could not be had (it threw). Activation fails
 * closed: a person is there to try again.
 */
final class SolutionEntitlementUnavailableException extends RuntimeException
{
    public static function forId(string $solutionId): self
    {
        return new self(sprintf('Solution "%s" was not turned on: the entitlement could not be checked.', $solutionId));
    }
}
