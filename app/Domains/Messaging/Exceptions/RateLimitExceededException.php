<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Exceptions;

use RuntimeException;

final class RateLimitExceededException extends RuntimeException
{
}
