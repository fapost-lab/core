<?php

declare(strict_types=1);

namespace App\Domains\Flow\Logging;

enum FlowLogStatus: string
{
    case Executed = 'executed';
    case Failed   = 'failed';
    case Conflict = 'conflict';
    case Terminal = 'terminal';
}
