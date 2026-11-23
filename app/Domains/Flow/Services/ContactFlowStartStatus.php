<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

/**
 * What {@see ContactFlowStarter} did under the contact's session lock.
 */
enum ContactFlowStartStatus: string
{
    /** The start key was already used; nothing was started. */
    case Duplicate = 'duplicate';

    /** The contact has no channel on an active channel of the assistant. */
    case NoChannel = 'no_channel';

    /** An operator or a conversation that is still alive holds the contact; the reason is on the outcome. */
    case Busy = 'busy';

    /** Run admission refused the start; it ended no conversation. */
    case NotAdmitted = 'not_admitted';

    /** A duplicate of this start won the race past the lock, and started once. */
    case RaceLost = 'race_lost';

    /** The conversation started, after the stale ones were ended. */
    case Started = 'started';
}
