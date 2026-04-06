<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Exceptions;

use RuntimeException;

/**
 * Thrown when {@see \App\Domains\Assistant\Contracts\CurrentAssistantInterface::get()} is called
 * and neither Filament tenancy nor an explicit override has resolved the assistant.
 */
final class CurrentAssistantNotResolvedException extends RuntimeException
{
    /**
     * Create an exception instance with a fixed message.
     */
    public static function make(): self
    {
        return new self('Current assistant has not been resolved for this request.');
    }
}
