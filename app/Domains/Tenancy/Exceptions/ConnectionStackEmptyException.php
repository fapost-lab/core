<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use LogicException;

final class ConnectionStackEmptyException extends LogicException
{
    public static function make(): self
    {
        return new self(
            'Cannot restore: connection stack is empty. ' .
            'restore() called without matching switchTo().'
        );
    }
}
