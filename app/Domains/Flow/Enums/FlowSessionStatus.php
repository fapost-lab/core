<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

enum FlowSessionStatus: string
{
    case Pending      = 'pending';
    case Active       = 'active';
    case WaitingInput = 'waiting_input';
    case Paused       = 'paused';
    case Completed    = 'completed';
    case Failed       = 'failed';
    case Cancelled    = 'cancelled';
}
