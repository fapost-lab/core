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

    /**
     * The statuses of a session that is running, waiting, or asleep in a `delay` or a nested subflow, all of which a
     * parent must treat as a live child. `Pending` is not one: a session is never left there.
     *
     * @return list<self>
     */
    public static function inFlight(): array
    {
        return [self::Active, self::WaitingInput, self::Paused, self::PausedSubflow];
    }

    /**
     * The values of {@see self::inFlight()}, for a query.
     *
     * @return list<string>
     */
    public static function inFlightValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::inFlight());
    }

    /**
     * The statuses a session never leaves.
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Completed, self::Ended, self::Failed, self::Cancelled, self::Expired, self::TerminatedByUser];
    }
}
