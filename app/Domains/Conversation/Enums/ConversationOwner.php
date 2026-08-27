<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * Who is currently answering a thread.
 *
 * Deliberately separate from {@see ConversationStatus}: status is the lifecycle
 * of the thread (open / closed / snoozed), ownership is who speaks next. A
 * thread taken over by an operator is still very much open.
 *
 * Stored in `conversations.owner_type`, which was reserved by the logging
 * migration ahead of the Inbox (spec §7.6).
 */
enum ConversationOwner: string
{
    /** The assistant answers automatically through the flow engine. */
    case Bot = 'bot';

    /** A staff member took the thread over; the flow engine stands down. */
    case Staff = 'staff';
}
