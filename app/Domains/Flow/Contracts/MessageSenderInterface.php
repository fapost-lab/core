<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use InvalidArgumentException;

interface MessageSenderInterface
{
    /**
     * Send one flow message to the contact and return the provider's message id.
     *
     * The payload always carries `content_type`, `node_id` and `idempotency_key`: the sender keys the delivery by
     * the session, the node and that key, so a step retried with the same key is not delivered twice. A node passes
     * its {@see \Fapost\Foundation\DTO\NodeExecutionContext::$idempotencyKey} and gives every other message
     * the same step may send (a hint) a key of its own.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException when the payload has no `node_id` or `idempotency_key`
     */
    public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string;
}
