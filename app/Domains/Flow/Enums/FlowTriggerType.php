<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

enum FlowTriggerType: string
{
    case Message  = 'message';
    case Schedule = 'schedule';
    case Webhook  = 'webhook';
    case Api      = 'api';
    case Event    = 'event';
}
