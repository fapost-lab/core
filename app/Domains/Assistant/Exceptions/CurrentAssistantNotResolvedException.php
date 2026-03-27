<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Exceptions;

use RuntimeException;

final class CurrentAssistantNotResolvedException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Current assistant has not been resolved for this request.');
    }
}
