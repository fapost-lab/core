<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Exceptions;

use RuntimeException;

final class InvalidSignatureException extends RuntimeException
{
}
