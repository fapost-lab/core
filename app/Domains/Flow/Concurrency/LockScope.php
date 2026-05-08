<?php

declare(strict_types=1);

namespace App\Domains\Flow\Concurrency;

/**
 * Identifies a session-execution lock by the (tenant, contact, assistant) triple.
 *
 * One scope == one lock key in Redis. Cross-assistant work for the same contact
 * uses distinct scopes (separate keys), so a contact may legitimately be talking
 * to two assistants in parallel.
 */
final readonly class LockScope
{
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public string $assistantId,
    ) {
    }

    public function key(): string
    {
        return "session_lock:{$this->tenantId}:{$this->contactId}:{$this->assistantId}";
    }
}
