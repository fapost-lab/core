<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Why an automation run was failed from outside the engine ({@see \App\Domains\Flow\Orchestration\AutomationRunDeadlineSweeper}).
 * A session the engine itself failed (a node error, the step limit) carries none.
 */
enum FlowRunFailureReason: string
{
    /** The run was still live past its deadline: its job was lost, or it waited longer than a run may live. */
    case DeadlineExceeded = 'deadline_exceeded';

    /** The run never left `pending`: the job that was to start it was lost. */
    case StartLost = 'start_lost';
}
