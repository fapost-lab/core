<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * Who is handling a thread right now. This is the thread's whole state in the inbox: there is no separate open /
 * closed lifecycle ({@see ConversationStatus} is retired and no longer shown).
 *
 * Stored in `conversations.owner_type`, which was reserved by the logging
 * migration ahead of the Inbox (spec §7.6).
 */
enum ConversationOwner: string
{
    /** The assistant answers automatically through the flow engine. */
    case Bot = 'bot';

    /** An operator was requested and nobody has accepted the thread yet; the flow engine stands down meanwhile. */
    case Queued = 'queued';

    /** A named staff member holds the thread; the flow engine stands down. */
    case Staff = 'staff';
}
