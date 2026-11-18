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

    /**
     * Whether a run this trigger starts has a contact. A message comes from one; an event trigger starts for the
     * contact the event carries. A schedule, a webhook and an API call start a run without one, so a flow with such
     * a trigger may hold no node that needs a contact (`ContactContextChecker`).
     */
    public function givesContact(): bool
    {
        return match ($this) {
            self::Message, self::Event               => true,
            self::Schedule, self::Webhook, self::Api => false,
        };
    }
}
