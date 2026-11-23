<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

/**
 * What {@see \App\Domains\Broadcasting\Services\AutomationBroadcasts::start()} did with a flow's request.
 *
 * `Started` and `Replayed` name a broadcast (a new one, or the one this node execution started before); the others
 * created nothing.
 */
enum AutomationBroadcastOutcome: string
{
    case Started         = 'started';
    case Replayed        = 'replayed';
    case Busy            = 'broadcast_running';
    case AudienceMissing = 'audience_missing';
    case MessageEmpty    = 'message_empty';

    public function namesBroadcast(): bool
    {
        return self::Started === $this || self::Replayed === $this;
    }
}
