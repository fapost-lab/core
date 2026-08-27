<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Exceptions;

use RuntimeException;

/**
 * Thrown when a handler tries to write to a contact path the platform reserves
 * (identity columns: id/tenant_id/external_id/platform; webhook-ingress JSONB
 * group meta.*). Reserved keys are not writable from flow nodes — the engine
 * fails the session rather than silently dropping the write.
 */
final class ReservedContactPathException extends RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf("Contact path '%s' is reserved and cannot be written from a flow node.", $path));
    }
}
