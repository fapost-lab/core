<?php

declare(strict_types=1);

namespace App\Domains\Audit\Exceptions;

use RuntimeException;

/**
 * An audit entry was not written. The action it records fails with it: the log is a control, and an action that
 * passes unrecorded is what it exists to prevent.
 */
final class AuditEntryNotRecordedException extends RuntimeException
{
    public static function loggingDisabled(string $action): self
    {
        return new self(sprintf('The audit entry "%s" was not recorded: activity logging is disabled.', $action));
    }
}
