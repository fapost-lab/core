<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use DateTimeInterface;

/**
 * Schedules the wake-up of a session parked on a `delay` node.
 *
 * The `delay` handler calls it on the first visit to the node; the scheduled
 * resume re-enters the session once {@code $resumeAt} is due. A resume may be
 * scheduled more than once for the same visit (a retried execution), so the
 * receiving side must treat a stale or duplicate resume as a no-op.
 */
interface DelayResumeSchedulerInterface
{
    public function schedule(string $tenantId, string $sessionId, string $nodeId, DateTimeInterface $resumeAt): void;
}
