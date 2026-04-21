<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Exceptions;

use RuntimeException;

final class UnsupportedUpdateTypeException extends RuntimeException
{
}
