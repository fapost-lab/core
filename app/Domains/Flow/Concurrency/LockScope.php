<?php

declare(strict_types=1);

namespace App\Domains\Flow\Concurrency;

use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\Exceptions\ContactlessSessionException;
use InvalidArgumentException;

/**
 * Identifies a session-execution lock: the (tenant, contact, assistant) triple of a conversation, or the
 * (tenant, trigger) pair of a contactless automation run.
 *
 * One scope == one lock key in Redis. Cross-assistant work for the same contact
 * uses distinct scopes (separate keys), so a contact may legitimately be talking
 * to two assistants in parallel. Contactless runs of one trigger are serialized on the trigger's key; two triggers
 * of one flow run in parallel.
 *
 * An empty contact without a trigger is refused: a contactless session would otherwise share one key with every
 * other contactless session of the tenant. An empty assistant stays valid (a conversation whose assistant was
 * deleted). {@see ofSession()} is the one place that picks the scope for a session.
 */
final readonly class LockScope
{
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public string $assistantId,
        public ?string $triggerId = null,
    ) {
        if (null !== $triggerId) {
            if ('' === $triggerId || '' !== $contactId || '' !== $assistantId) {
                throw new InvalidArgumentException('A trigger lock scope names a trigger, and no contact or assistant.');
            }

            return;
        }

        if ('' === $contactId) {
            throw ContactlessSessionException::forLockScope($tenantId);
        }
    }

    /**
     * The lock of the contactless runs of one trigger.
     */
    public static function forTrigger(string $tenantId, string $triggerId): self
    {
        return new self($tenantId, '', '', $triggerId);
    }

    /**
     * The lock a session runs under: its contact's conversation lock when it has a contact, its trigger's lock when
     * it has none. A contactless session without a trigger has no lock and throws.
     *
     * @throws ContactlessSessionException
     */
    public static function ofSession(FlowSession $session): self
    {
        $tenantId = (string) $session->tenant_id;

        if (null !== $session->contact_id && '' !== $session->contact_id) {
            return new self($tenantId, $session->contact_id, (string) $session->assistant_id);
        }

        if (null !== $session->trigger_id && '' !== $session->trigger_id) {
            return self::forTrigger($tenantId, $session->trigger_id);
        }

        throw ContactlessSessionException::forLockScope($tenantId);
    }

    public function key(): string
    {
        if (null !== $this->triggerId) {
            return "session_lock:{$this->tenantId}:trigger:{$this->triggerId}";
        }

        return "session_lock:{$this->tenantId}:{$this->contactId}:{$this->assistantId}";
    }
}
