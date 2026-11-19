<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Exceptions\FlowExecutionLimitExceededException;
use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\LoopNodeHandler;
use Carbon\CarbonImmutable;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;

/**
 * The step budget of one engine call, aware of loops.
 *
 * - Steps outside a loop body count against the shared budget (`flow.execution.max_iterations`). The pass of a
 *   `loop` node that enters the loop is one of them, so every entry into a loop costs the shared budget a step.
 * - Steps inside a loop body — the body, its `loop_end` and the `loop` node's re-evaluations — do not. One iteration
 *   may take at most the shared budget's worth of steps, counted from the last `loop_end`.
 * - A loop may not start more than `flow.execution.max_loop_iterations` iterations. It is checked against the
 *   loop's persisted iterator.
 * - Every step counts against the run's ceiling ({@see ceiling()}): the most steps the two settings could need, and
 *   never more than {@see FlowEngineInterface::MAX_STEPS_PER_RUN}.
 *
 * A conversation ({@see forConversation()}) counts all of this per engine call; a loop's iterations from the iterator
 * the call found when it first reached the loop, so a loop that waits for the contact in its body ("show the menu
 * again") goes round once per message and is not stopped after so many messages. A conversation call has no
 * execution segments, so a loop body there also stops once the call has run {@see FlowEngineInterface::MAX_SEGMENT_SECONDS}
 * seconds: a long loop of slow nodes fails with the loop named instead of outliving the job's timeout and leaving the
 * session stuck `active`. An automation run
 * ({@see forAutomationRun()}) counts it over the whole run: it starts from the session's persisted `step_count` (steps
 * outside loop bodies) and `loop_step_count` (steps inside them), knows from the iterators in state which loop body a
 * segment starts in, and counts a loop's iterations from its first one — so neither a runaway nor a loop escapes its
 * limit by spreading over segments, delay wake-ups and job retries. The engine persists each step's count by
 * {@see lastStepInLoop()}.
 *
 * A new instance per engine call (built with `new`, never bound): plain values only, nothing survives the call.
 */
final class StepBudget
{
    private ?string $activeLoopNodeId;

    private int $iterationSteps = 0;

    private bool $lastStepInLoop = false;

    /** @var array<string, int> per loop node, the iterations it had started before this call reached it */
    private array $iteratorBaselines = [];

    /** When a conversation call started; null for an automation run, whose segments bound its time. */
    private readonly ?CarbonImmutable $startedAt;

    private function __construct(
        private readonly int $maxSharedSteps,
        private readonly int $maxLoopIterations,
        private readonly bool $runWide,
        private int $sharedSteps,
        private int $loopSteps,
        ?string $activeLoopNodeId,
    ) {
        $this->activeLoopNodeId = $activeLoopNodeId;
        $this->startedAt        = $runWide ? null : CarbonImmutable::now();
    }

    public static function forConversation(int $maxSharedSteps, int $maxLoopIterations): self
    {
        return new self($maxSharedSteps, $maxLoopIterations, false, 0, 0, null);
    }

    /**
     * @param  int  $sharedSteps  the run's persisted steps outside loop bodies (`step_count`)
     * @param  int  $loopSteps  the run's persisted steps inside loop bodies (`loop_step_count`)
     * @param  list<mixed>  $nodes  the definition's nodes, to find the loop body the call starts in
     * @param  array<string, mixed>  $state  the session state
     */
    public static function forAutomationRun(
        int $maxSharedSteps,
        int $maxLoopIterations,
        int $sharedSteps,
        int $loopSteps,
        array $nodes,
        array $state,
    ): self {
        return new self($maxSharedSteps, $maxLoopIterations, true, $sharedSteps, $loopSteps, self::loopInProgress($nodes, $state));
    }

    /**
     * Counts the step about to run at {@code $nodeId}; returns the limit it crosses, or null when it may run.
     */
    public function charge(string $nodeId): ?FlowExecutionLimitExceededException
    {
        if ($this->sharedSteps + $this->loopSteps >= $this->ceiling()) {
            return FlowExecutionLimitExceededException::ceilingReached($nodeId, $this->ceiling());
        }

        $this->lastStepInLoop = null !== $this->activeLoopNodeId;

        if (null !== $this->activeLoopNodeId) {
            if (null !== $this->startedAt
                && $this->startedAt->diffInSeconds(CarbonImmutable::now()) >= FlowEngineInterface::MAX_SEGMENT_SECONDS) {
                return FlowExecutionLimitExceededException::loopTooSlow($this->activeLoopNodeId, FlowEngineInterface::MAX_SEGMENT_SECONDS);
            }

            ++$this->loopSteps;

            return ++$this->iterationSteps > $this->maxSharedSteps
                ? FlowExecutionLimitExceededException::loopIterationTooLong($this->activeLoopNodeId, $this->maxSharedSteps)
                : null;
        }

        return ++$this->sharedSteps > $this->maxSharedSteps
            ? FlowExecutionLimitExceededException::stepsExceeded($nodeId, $this->maxSharedSteps)
            : null;
    }

    /**
     * Whether the step {@see charge()} last counted ran inside a loop body: an automation run persists it in
     * `loop_step_count` rather than `step_count`.
     */
    public function lastStepInLoop(): bool
    {
        return $this->lastStepInLoop;
    }

    /**
     * The most steps a run may take in all, loop bodies included: the shared budget once outside loops and once per
     * loop iteration, capped at {@see FlowEngineInterface::MAX_STEPS_PER_RUN}. An automation run checks it against
     * its steps over all segments.
     */
    public function ceiling(): int
    {
        return min(
            FlowEngineInterface::MAX_STEPS_PER_RUN,
            max(1, $this->maxSharedSteps) * (max(0, $this->maxLoopIterations) + 1),
        );
    }

    /**
     * Follows a node's result into and out of a loop body; returns the limit a loop crossed, or null.
     *
     * @param  array<string, mixed>  $node   the node as stored in the definition
     * @param  array<string, mixed>  $state  the session state the node ran against
     */
    public function record(array $node, NodeExecutionResult $result, array $state): ?FlowExecutionLimitExceededException
    {
        $type = $node['type'] ?? null;

        if (LoopEndNodeHandler::TYPE === $type) {
            $this->iterationSteps = 0;

            return null;
        }

        if (LoopNodeHandler::TYPE !== $type || NodeExecutionStatus::Executed !== $result->status) {
            return null;
        }

        $nodeId = is_string($node['id'] ?? null) ? $node['id'] : '';

        if (LoopNodeHandler::LOOP_HANDLE !== $result->sourceHandle) {
            // The loop exits: what follows counts against the shared budget again.
            $this->activeLoopNodeId = null;
            $this->iterationSteps   = 0;

            return null;
        }

        $iterator = $this->iterator($node, $result, $state);

        if (!$this->runWide) {
            $this->iteratorBaselines[$nodeId] ??= $iterator - 1;
            $iterator -= $this->iteratorBaselines[$nodeId];
        }

        if ($iterator > $this->maxLoopIterations) {
            return FlowExecutionLimitExceededException::loopIterationsExceeded($nodeId, $this->maxLoopIterations);
        }

        if ($this->activeLoopNodeId !== $nodeId) {
            $this->activeLoopNodeId = $nodeId;
            $this->iterationSteps   = 0;
        }

        return null;
    }

    /**
     * The loop whose body a call starts in: the first `loop` node whose iterator is set in state. A loop sets it on
     * entry and clears it on exit, and loops never nest, so at most one loop is in progress; two sequential loops
     * sharing an iterator name are told apart by their next pass, which re-points the budget at the loop that ran.
     *
     * @param  list<mixed>  $nodes
     * @param  array<string, mixed>  $state
     */
    private static function loopInProgress(array $nodes, array $state): ?string
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || LoopNodeHandler::TYPE !== ($node['type'] ?? null) || !is_string($node['id'] ?? null)) {
                continue;
            }

            if (null !== data_get($state, 'flow.' . self::iteratorName($node))) {
                return $node['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function iteratorName(array $node): string
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        return is_string($config['iterator_name'] ?? null) && '' !== $config['iterator_name']
            ? $config['iterator_name']
            : LoopNodeHandler::DEFAULT_ITERATOR_NAME;
    }

    /**
     * The 1-based number of the iteration the loop is about to run: what the node writes, else what state holds.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $state
     */
    private function iterator(array $node, NodeExecutionResult $result, array $state): int
    {
        $path = 'flow.' . self::iteratorName($node);

        $value = array_key_exists($path, $result->stateChanges)
            ? $result->stateChanges[$path]
            : data_get($state, $path);

        return is_numeric($value) ? (int) $value : 1;
    }
}
