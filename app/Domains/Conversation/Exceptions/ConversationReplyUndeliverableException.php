<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Exceptions;

use RuntimeException;

/**
 * Thrown when an operator reply cannot be dispatched — the thread's channel is
 * missing/inactive, or the contact has no linkage to it (no chat id to send to).
 */
final class ConversationReplyUndeliverableException extends RuntimeException
{
}
