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
     *
     * @throws ConversationReplyUndeliverableException  No active channel linkage to send through.
     */
    public function send(
        Conversation $conversation,
        string $text,
        string $staffUserId,
        ?string $mediaFileId = null,
    ): DeliveryResult;
}
