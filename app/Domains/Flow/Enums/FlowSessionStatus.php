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
}
