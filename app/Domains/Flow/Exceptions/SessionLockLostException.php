<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;

/**
 * Raised when a heartbeat tick finds the session lock no longer owned by this
 * worker (token drift — the TTL lapsed and another worker claimed the slot).
 *
 * Per ADR Message Routing & Concurrency Control the current execution must be
 * abandoned immediately: continuing would race the new owner over session
 * state. Optimistic locking on {@code flow_sessions.version} is the backstop
 * for writes already in flight, not a licence to keep going.
 */
final class SessionLockLostException extends RuntimeException
{
    public function __construct(string $lockKey)
    {
        parent::__construct("Session lock ownership lost: {$lockKey}");
    }
}
