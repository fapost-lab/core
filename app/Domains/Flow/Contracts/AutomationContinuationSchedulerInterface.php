<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Queues the next segment of an automation run that stopped at its saved position because its segment budget ran
 * out ({@see \App\Domains\Flow\Services\ExecutionSegment}).
 *
 * The engine calls it after the transaction that wrote {@code $segmentId} on the run's root session committed; the
 * receiving side runs the run only while its root still carries that segment id, so a duplicate or stale
 * continuation is a no-op.
 */
interface AutomationContinuationSchedulerInterface
{
    public function schedule(string $tenantId, string $rootSessionId, string $segmentId): void;
}
