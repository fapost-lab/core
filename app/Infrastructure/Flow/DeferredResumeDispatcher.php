<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * Queues a job that must run later — a `delay` wake-up, a `send_message` timeout.
 *
 * A `sync` queue ignores the delay and runs the job at once, inside the engine
 * run that scheduled it: the session lock is re-entrant for that worker, so the
 * resume would re-enter a session the engine is still executing (a timeout would
 * fire the moment its keyboard is sent). A deferred resume cannot work without an
 * asynchronous queue, so on `sync` it is not queued at all and a warning names
 * what was skipped.
 */
final readonly class DeferredResumeDispatcher
{
    /**
     * @param  array<string, string>  $context  identifiers for the warning
     */
    public function dispatch(ShouldQueue $job, DateTimeInterface $runAt, array $context): void
    {
        if (Queue::connection() instanceof SyncQueue) {
            Log::warning('flow.deferred_resume.skipped_on_sync_queue', [
                'job' => $job::class,
                ...$context,
            ]);

            return;
        }

        dispatch($job)->delay($runAt)->afterCommit();
    }
}
