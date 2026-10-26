<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * Retired lifecycle state of a conversation thread: the inbox no longer shows or changes it (what a thread needs is
 * told by {@see ConversationOwner}). The column stays, and the store still sets `Open` when it creates a thread.
 */
enum ConversationStatus: string
{
    case Open    = 'open';
    case Closed  = 'closed';
    case Snoozed = 'snoozed';
}
