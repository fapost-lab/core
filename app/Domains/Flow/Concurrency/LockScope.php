<?php

declare(strict_types=1);

namespace App\Domains\Flow\Concurrency;

use App\Domains\Flow\State\Exceptions\ContactlessSessionException;

/**
 * Identifies a session-execution lock by the (tenant, contact, assistant) triple.
 *
 * One scope == one lock key in Redis. Cross-assistant work for the same contact
 * uses distinct scopes (separate keys), so a contact may legitimately be talking
 * to two assistants in parallel.
 *
 * An empty contact is refused: a contactless session would otherwise share one key with every other contactless
 * session of the tenant. Contactless runs get a lock of their own in a later step; an empty assistant stays valid
 * (a conversation whose assistant was deleted).
 */
final readonly class LockScope
{
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public string $assistantId,
    ) {
        if ('' === $contactId) {
            throw ContactlessSessionException::forLockScope($tenantId);
        }
    }

    public function key(): string
    {
        return "session_lock:{$this->tenantId}:{$this->contactId}:{$this->assistantId}";
    }
}
