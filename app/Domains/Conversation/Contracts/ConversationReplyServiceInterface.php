<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Contracts;

use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Models\Conversation;
use Fapost\Foundation\Messaging\DeliveryResult;

/**
 * Sends an operator reply into an existing thread through the generic outbound
 * funnel ({@see \App\Domains\Messaging\MessageSender}), attributing the message
 * to the acting staff user so the capture pipeline logs it correctly.
 */
interface ConversationReplyServiceInterface
{
    /**
     * @param  string|null  $mediaFileId  Attachment from the tenant media library;
     *                                    `$text` then travels as its caption.
     * @param  string|null  $requestId    The operator's submission, stable across its retries: it becomes the
     *                                    message's idempotency key, so the outbound funnel, the volume gate and
     *                                    the transcript count a repeated submission once. Without it every call
     *                                    is a new message.
     *
     * @throws ConversationReplyUndeliverableException  No active channel linkage to send through.
     */
    public function send(
        Conversation $conversation,
        string $text,
        string $staffUserId,
        ?string $mediaFileId = null,
        ?string $requestId = null,
    ): DeliveryResult;
}
