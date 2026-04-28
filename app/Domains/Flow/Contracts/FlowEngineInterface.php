<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use FAPost\Foundation\DTO\IncomingMessage;

interface FlowEngineInterface
{
    /**
     * @param  array<string, mixed>  $initialState  Merged into session state after system bootstrap keys are set
     */
    public function start(
        FlowDefinition $definition,
        Contact $contact,
        array $initialState = [],
    ): FlowSession;

    /**
     * Loads the frozen graph via {@see FlowSession::$flow_definition_id} → {@see FlowDefinition::$id}
     * (version snapshot row). Runtime navigation uses {@see FlowSession::$current_node_id} only.
     */
    public function resume(
        FlowSession $session,
        IncomingMessage $message,
    ): FlowSession;

    /**
     * Creates a new session starting at the node connected to the given output handle of $nodeId,
     * using $definition as the frozen snapshot.
     *
     * Used for keep-forever inline button re-entry: the user pressed a button from a completed
     * session, and we need to continue the flow from the branch that button leads to.
     * If $outputHandle has no connected node the session is created in Completed status immediately.
     *
     * @param  array<string, mixed>  $initialState
     */
    public function resumeFromNode(
        FlowDefinition $definition,
        Contact $contact,
        string $nodeId,
        string $outputHandle,
        array $initialState = [],
    ): FlowSession;
}
