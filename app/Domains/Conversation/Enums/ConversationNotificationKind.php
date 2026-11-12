<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * What a notification to an operator is about; stored as `kind` in the notification row the bell reads.
 */
enum ConversationNotificationKind: string
{
    /** A new message from the contact in a thread the operator holds. */
    case Message = 'conversation_message';

    /** A thread another operator handed to the recipient. */
    case Transferred = 'conversation_transferred';

    /** A thread an administrator took from the recipient. */
    case Seized = 'conversation_seized';

    /** A contact waiting for an operator. */
    case Requested = 'conversation_requested';
}
