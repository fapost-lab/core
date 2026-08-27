<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;

final class FlowExecutionLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'Flow execution exceeded the configured maximum iteration count.',
    ) {
        parent::__construct($message);
    }
}
