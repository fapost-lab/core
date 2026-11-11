<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * Where an operator request stands. A flow's `request_operator` node waits while the request is `pending` or
 * `accepted`; the three final states are the node's exits.
 */
enum OperatorRequestState: string
{
    /** The thread is `queued`: nobody has accepted it yet. */
    case Pending = 'pending';

    /** An operator accepted the thread and handles it now. */
    case Accepted = 'accepted';

    /** The operator finished and gave the thread back to the assistant. */
    case Returned = 'returned';

    /** The contact cancelled the request (the cancel button or a command that ends the session). */
    case Cancelled = 'cancelled';

    /** Nobody accepted the request in time. */
    case Timeout = 'timeout';

    /**
     * Whether the request still holds the flow: the node waits until the request reaches a final state.
     */
    public function isWaiting(): bool
    {
        return self::Pending === $this || self::Accepted === $this;
    }
}
