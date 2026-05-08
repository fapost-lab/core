<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

/**
 * Allowed action types for tenant-configurable global commands (V1).
 *
 * Per ADR Message Routing § Action Types. Extending this enum requires bumping
 * the assistants.commands JSON schema contract in lockstep with validators.
 */
enum CommandActionType: string
{
    /** Force-terminate the active session, optionally sending an ack message. */
    case TerminateSession = 'terminate_session';

    /** Terminate current session (if any) and start the specified flow. */
    case StartFlow = 'start_flow';

    /** Send a single message without starting a flow. */
    case SendMessage = 'send_message';
}
