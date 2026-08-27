<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;

final class SessionLockTimeoutException extends RuntimeException
{
    public function __construct(string $lockKey)
    {
        parent::__construct("Could not acquire session lock: {$lockKey}");
    }
}
