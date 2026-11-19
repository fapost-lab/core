<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;

/**
 * A flow run crossed one of the engine's step limits ({@see \App\Domains\Flow\Services\StepBudget}); the session
 * fails. The message names the limit and the node it was crossed at.
 */
final class FlowExecutionLimitExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'Flow execution exceeded the configured maximum iteration count.',
    ) {
        parent::__construct($message);
    }

    /** More steps outside loop bodies than `flow.execution.max_iterations` allows. */
    public static function stepsExceeded(string $nodeId, int $max): self
    {
        return new self(sprintf(
            "Flow execution exceeded %d steps outside loops (flow.execution.max_iterations) at node '%s'.",
            $max,
            $nodeId,
        ));
    }

    /** A loop about to start more iterations than `flow.execution.max_loop_iterations` allows. */
    public static function loopIterationsExceeded(string $loopNodeId, int $max): self
    {
        return new self(sprintf(
            "Loop node '%s' exceeded %d iterations (flow.execution.max_loop_iterations).",
            $loopNodeId,
            $max,
        ));
    }

    /** One iteration of a loop took more steps than `flow.execution.max_iterations` allows without reaching its `loop_end`. */
    public static function loopIterationTooLong(string $loopNodeId, int $max): self
    {
        return new self(sprintf(
            "One iteration of loop node '%s' exceeded %d steps (flow.execution.max_iterations) without reaching its loop_end.",
            $loopNodeId,
            $max,
        ));
    }

    /** A conversation's loop still running after the seconds one engine call may take inside a loop body. */
    public static function loopTooSlow(string $loopNodeId, int $seconds): self
    {
        return new self(sprintf(
            "Loop node '%s' was still running after %d seconds of one engine call.",
            $loopNodeId,
            $seconds,
        ));
    }

    /** The ceiling on all steps of one run, loop bodies included. */
    public static function ceilingReached(string $nodeId, int $ceiling): self
    {
        return new self(sprintf(
            "Flow execution reached the ceiling of %d steps per run at node '%s'.",
            $ceiling,
            $nodeId,
        ));
    }
}
