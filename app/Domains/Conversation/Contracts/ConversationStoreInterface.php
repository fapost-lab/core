<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Contracts;

use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;

/**
 * Low-level storage backend port. Each driver (Postgres today, ClickHouse later)
 * implements this; nothing outside a driver knows where messages physically live
 * (spec §2 — the main architectural invariant).
 */
interface ConversationStoreInterface
{
    /**
     * Guarantee the thread exists and return its id. Idempotent by {@see ConversationRef}.
     */
    public function ensureConversation(ConversationRef $ref): string;

    /**
     * Append a message. Idempotent by (conversation_id, direction, idempotency_key).
     *
     * @return string|null  The stored message id, or null when the append was a
     *                      no-op because the message already existed (duplicate).
     */
    public function appendMessage(string $conversationId, MessageLogEntry $entry): ?string;

    /**
     * Replace the media descriptors of a stored message (async media fetch, §6.1).
     *
     * @param  list<array<string, mixed>>  $media
     */
    public function updateMessageMedia(string $messageId, array $media): void;

    /**
     * Update the delivery status of an outbound message by provider message id.
     */
    public function updateStatus(string $providerMessageId, DeliveryStatus $status): void;
}
