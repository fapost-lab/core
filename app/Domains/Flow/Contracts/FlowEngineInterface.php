<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use Fapost\Foundation\DTO\IncomingMessage;

interface FlowEngineInterface
{
    /**
     * The platform setting that caps the steps of one engine run outside loop bodies, and the steps of one loop
     * iteration (env default `FLOW_MAX_ITERATIONS`); past it the session fails with
     * `FlowExecutionLimitExceededException`, except a subflow child, which hands its parent the `failed` exit and
     * returns.
     */
    public const string MAX_ITERATIONS_SETTING = 'flow.execution.max_iterations';

    /**
     * The platform setting that caps the iterations of one loop (env default `FLOW_MAX_LOOP_ITERATIONS`), checked
     * against the loop's persisted iterator — per outermost engine call for a conversation, over the whole run for an
     * automation; past it the session fails like at {@see MAX_ITERATIONS_SETTING}. It accepts at most
     * {@see MAX_LOOP_ITERATIONS_LIMIT}.
     */
    public const string MAX_LOOP_ITERATIONS_SETTING = 'flow.execution.max_loop_iterations';

    /**
     * The hard ceiling on every step of one engine run, loop bodies included, whatever the settings say; an
     * automation run counts its steps over all its segments (`flow_sessions.step_count` + `loop_step_count`). Small
     * settings lower it ({@see \App\Domains\Flow\Services\StepBudget::ceiling()}).
     */
    public const int MAX_STEPS_PER_RUN = 10000;

    /**
     * The highest value {@see MAX_LOOP_ITERATIONS_SETTING} accepts: three steps per iteration (loop, a one-node body,
     * loop_end) still fit under {@see MAX_STEPS_PER_RUN}.
     */
    public const int MAX_LOOP_ITERATIONS_LIMIT = (self::MAX_STEPS_PER_RUN - 1000) / 3;

    /**
     * The platform setting that caps the steps of one execution segment of an automation run (env default
     * `FLOW_AUTOMATION_SEGMENT_STEPS`); past it the run stops at its saved position and continues in a new job.
     */
    public const string SEGMENT_STEPS_SETTING = 'flow.automation.segment_steps';

    /**
     * The platform setting that caps the seconds of one execution segment of an automation run (env default
     * `FLOW_AUTOMATION_SEGMENT_SECONDS`). Its maximum keeps the budget plus the slowest node under the job timeout.
     */
    public const string SEGMENT_SECONDS_SETTING = 'flow.automation.segment_seconds';

    /** The most seconds a segment may run, whatever the setting or its installation default say. */
    public const int MAX_SEGMENT_SECONDS = 30;

    /**
     * Creates a session at the definition's entry node and runs it to its first wait point or its end — for an
     * automation run, to the end of its first execution segment; the rest runs in new jobs.
     *
     * With a contact the session is the contact's conversation with the current assistant, as always. Without
     * one ({@code $contact} null) it is a contactless automation run: the tenant comes from the definition, the
     * assistant only from {@code $origin} (none by default — never the ambient current assistant), and a
     * `contact.*` / `module.*` read, a contact write or a per-contact session lock on it throws
     * {@see \App\Domains\Flow\State\Exceptions\ContactlessSessionException}.
     *
     * @param  array<string, mixed>  $initialState  Merged into session state after system bootstrap keys are set
     * @param  FlowRunOrigin|null  $origin  Run kind and trigger; defaults to a conversation with a contact and to an
     *   automation without one. A conversation without a contact is refused with {@see \InvalidArgumentException}.
     */
    public function start(
        FlowDefinition $definition,
        ?Contact $contact,
        array $initialState = [],
        ?FlowRunOrigin $origin = null,
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
     *
     * @param  array<string, mixed>  $outputs  Session variables (`flow.<name>` => value) the child hands back through
     *                                         the node's `output_mapping`, written in the same save that advances
     *                                         the parent; empty when nothing is mapped or the child did not succeed.
     */
    public function resumeAfterSubflow(FlowSession $parent, string $sourceHandle, array $outputs = []): FlowSession;
}
