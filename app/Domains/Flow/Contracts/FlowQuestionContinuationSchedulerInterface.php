<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Queues the continuation of a run whose question was just answered, once the answer is committed.
 *
 * The press is received under the addressee's session lock, while the run lives under another (its trigger's, or
 * another contact's), so the run is never continued in place: a job takes the run's own lock.
 */
interface FlowQuestionContinuationSchedulerInterface
{
    public function schedule(string $tenantId, string $questionId): void;
}
