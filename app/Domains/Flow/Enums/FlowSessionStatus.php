<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

enum FlowSessionStatus: string
{
    case Pending          = 'pending';
    case Active           = 'active';
    case WaitingInput     = 'waiting_input';
    case Paused           = 'paused';
    case PausedSubflow    = 'paused_subflow';
    case Completed        = 'completed';
    case Ended            = 'ended';
    case Failed           = 'failed';
    case Cancelled        = 'cancelled';
    case Expired          = 'expired';
    case TerminatedByUser = 'terminated_by_user';

    /**
     * The statuses an operator calls "live": running, or waiting for the contact or a subflow. The dashboard counts
     * them and the sessions list shows them by default.
     *
     * `Paused` is left out on purpose, as the Filament list did: it is a `delay` node sleeping until its `resume_at`,
     * which neither the contact nor an operator can move, so it is not what "live" triage looks for, and a list of live
     * sessions would otherwise fill with every broadcast flow waiting out a delay. It stays reachable through the status
     * filter, and its page shows it like any other.
     *
     * @return list<self>
     */
    public static function live(): array
    {
        return [self::Active, self::WaitingInput, self::PausedSubflow];
    }
}
