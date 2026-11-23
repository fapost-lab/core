<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * What an {@see \App\Domains\Flow\Contracts\AudienceBroadcasterInterface} did with a node's request. `Started` and
 * `Replayed` go on by `default`; the others take `error`, and their value is the node's `error_type`.
 */
enum AudienceBroadcastOutcome: string
{
    case Started         = 'started';
    case Replayed        = 'replayed';
    case Busy            = 'broadcast_running';
    case AudienceMissing = 'audience_missing';
    case MessageEmpty    = 'message_empty';

    public function startedBroadcast(): bool
    {
        return self::Started === $this || self::Replayed === $this;
    }
}
