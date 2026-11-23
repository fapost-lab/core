<?php

declare(strict_types=1);

namespace App\Domains\Flow\Admission;

/**
 * Why run admission refused a trigger's run, checked in this order: a paused trigger, the run rate breaker, the plan's
 * run limit. A pause records its own reason with the same values (today only {@see self::RateBreaker}).
 */
enum RefusalReason: string
{
    case TriggerPaused = 'trigger_paused';
    case RateBreaker   = 'rate_breaker';
    case PlanLimit     = 'plan_limit';
}
