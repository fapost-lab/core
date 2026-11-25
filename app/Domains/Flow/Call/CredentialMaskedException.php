<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use RuntimeException;

/**
 * An exception thrown while a request carrying a saved credential was sent, whose message quoted one of the
 * credential's secret strings. It carries the message with them replaced by `***` and no previous exception (the
 * original still holds the raw message), so the engine's trace, a worker log and the builder print it masked.
 */
final class CredentialMaskedException extends RuntimeException
{
}
