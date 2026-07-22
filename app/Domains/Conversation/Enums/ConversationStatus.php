<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * Lifecycle state of a conversation thread (foundation for the future Inbox).
 */
enum ConversationStatus: string
{
    case Open    = 'open';
    case Closed  = 'closed';
    case Snoozed = 'snoozed';
}
