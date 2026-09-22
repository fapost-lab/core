<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use Fapost\Foundation\DTO\IncomingMessage;

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

    /**
     * Drive the execution loop for an already-persisted session — used when
     * the engine itself spawned the session (e.g. as a subflow child) and
     * needs to walk it to its first wait point without going through the
     * full {@see start()} bootstrap (no new row, no new analytics event),
     * and to wake a session parked on a `delay` node or on `paused` (a
     * `delayed(resumeAt: ...)` result), neither of which has an inbound
     * message to resume with.
     *
     * @param  bool  $resumedAfterDelay  Passed to the first node's
     *   {@see \Fapost\Foundation\DTO\NodeExecutionContext::$resumedAfterDelay}
     *   only — set when this run wakes a `paused` session at its `resumeAt`.
     */
    public function runSession(FlowSession $session, bool $resumedAfterDelay = false): FlowSession;

    /**
     * Resume a parent session after its subflow child reached an end node.
     * The engine resolves the next node from {@code current_node_id} via
     * {@code $sourceHandle}, advances the parent, and continues executing.
     */
    public function resumeAfterSubflow(FlowSession $parent, string $sourceHandle): FlowSession;
}
