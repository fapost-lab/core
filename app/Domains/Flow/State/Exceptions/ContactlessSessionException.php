<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Exceptions;

use RuntimeException;

/**
 * Thrown when something needs the contact of a flow session that has none — a contactless run started by a
 * schedule, an event, a webhook or the API. Reading `contact.*` or `module.*`, writing `contact.*`, or building a
 * per-contact session lock on such a session is refused with this error instead of failing later on a null; the
 * engine fails the session like any other handler error.
 */
final class ContactlessSessionException extends RuntimeException
{
    public static function forRead(string $path): self
    {
        return new self(sprintf("Path '%s' needs a contact, and this flow session runs without one.", $path));
    }

    public static function forWrite(string $path): self
    {
        return new self(sprintf("Path '%s' cannot be written: this flow session runs without a contact.", $path));
    }

    public static function forLockScope(string $tenantId): self
    {
        return new self(sprintf(
            "A session lock needs a contact; a flow session of tenant '%s' runs without one.",
            $tenantId,
        ));
    }
}
