<?php

declare(strict_types=1);

namespace App\Domains\Flow\Routing;

/**
 * Output of {@see SessionStateRouter}: the high-level intent the routing
 * pipeline should follow once a lock has been taken (or attempted).
 */
enum SessionRoutingDecision: string
{
    /** Run trigger resolution and start a new top-level session. */
    case StartViaTrigger = 'start_via_trigger';

    /** Resume an existing session that's awaiting input. */
    case ResumeWaiting = 'resume_waiting';

    /** Drop with the configured "busy" notice (or silently for paused/active). */
    case DropBusy = 'drop_busy';

    /** Drop silently — message arrived between unusual lock states. */
    case DropSilent = 'drop_silent';
}
