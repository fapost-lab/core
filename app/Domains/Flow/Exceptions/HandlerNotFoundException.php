<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;
use Throwable;

final class HandlerNotFoundException extends RuntimeException
{
    public static function forTypeAndVersion(string $type, int $version, ?Throwable $previous = null): self
    {
        return new self(
            message: "Flow node handler not found for type '{$type}' at version {$version}.",
            previous: $previous,
        );
    }
}
