<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * What placing an operator request did to the thread.
 */
enum OperatorRequestPlacementOutcome: string
{
    /**
     * The thread went from the bot to `queued` with this request, or it already waits for this very request (a retry of
     * the same node in the same session): the contact is told and the operators are notified (both are idempotent per
     * request).
     */
    case Placed = 'placed';

    /**
     * The thread was already waiting for an operator or held by one: the session now waits for that state instead —
     * nobody is notified again and the contact gets no "request sent" text.
     */
    case Bound = 'bound';

    /** The tenant keeps no conversation log, so an operator would see an empty thread: nothing was placed. */
    case LoggingDisabled = 'logging_disabled';

    /** The contact has no active channel at the assistant: there is no thread to place the request on. */
    case NoChannel = 'no_channel';
}
