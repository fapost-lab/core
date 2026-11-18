<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * What started a flow session — `flow_sessions.run_kind`. A conversation is a contact talking to an assistant (an
 * inbound message, a button, an event for that contact); an automation is a run started by a trigger on its own,
 * with or without a contact. A session without a contact is always an automation.
 */
enum FlowRunKind: string
{
    case Conversation = 'conversation';
    case Automation   = 'automation';
}
