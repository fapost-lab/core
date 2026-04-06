<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

enum NodeExecutionStatus: string
{
    case Completed       = 'completed';
    case WaitingForInput = 'waiting';
    case Delayed         = 'delayed';
    case Failed          = 'failed';
}
