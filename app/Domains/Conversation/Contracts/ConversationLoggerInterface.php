<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Contracts;

use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;

/**
 * Write-port for the conversation transcript. Called from capture sites (webhook
 * inbound, message sender outbound, broadcast). Non-blocking: implementations
 * dispatch persistence asynchronously and must never throw back into the caller —
 * a logging failure must not break message processing (spec §8).
 */
interface ConversationLoggerInterface
{
    /**
     * Record one message in the transcript. Resolves/creates the conversation and
     * dispatches the persist job; safe to call from the hot path.
     */
    public function log(MessageLogEntry $entry): void;

    /**
     * Update the delivery status of a previously-logged outbound message, keyed by
     * its provider message id (WhatsApp delivered/read status webhooks, spec §7.5).
     */
    public function updateDeliveryStatus(string $providerMessageId, DeliveryStatus $status): void;
}
