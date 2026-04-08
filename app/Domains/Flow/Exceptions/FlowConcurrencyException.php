<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;
use Throwable;

final class FlowConcurrencyException extends RuntimeException
{
    public static function forSession(string $sessionId, ?Throwable $previous = null): self
    {
        return new self(
            message: "Optimistic lock conflict while persisting flow session '{$sessionId}'.",
            previous: $previous,
        );
    }
}
