<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\FlowQuestionContinuationSchedulerInterface;
use App\Jobs\Flow\ContinueAfterFlowQuestionJob;

/**
 * Queues {@see ContinueAfterFlowQuestionJob} once the answer is committed.
 */
final readonly class QueuedFlowQuestionContinuationScheduler implements FlowQuestionContinuationSchedulerInterface
{
    public function schedule(string $tenantId, string $questionId): void
    {
        dispatch(new ContinueAfterFlowQuestionJob($tenantId, $questionId))->afterCommit();
    }
}
