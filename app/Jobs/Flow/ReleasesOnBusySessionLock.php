<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use Closure;

/**
 * The busy-lock policy of a Flow job that runs under a session lock another job may hold for a whole segment: a busy
 * lock puts the job back in the queue after {@see self::LOCK_BUSY_RELEASE_SECONDS} instead of spending an attempt,
 * and only real failures count, three at most ({@see $maxExceptions}). How long the releases may go on is the job's
 * own `retryUntil()`: its window starts at a different moment for each job.
 *
 * Used with Laravel's `Queueable`, which provides `release()`.
 */
trait ReleasesOnBusySessionLock
{
    public const int LOCK_BUSY_RELEASE_SECONDS = 10;

    /** Real failures end the job after three; a busy lock only releases it, until `retryUntil()`. */
    public int $maxExceptions = 3;

    /**
     * Run {@code $work}; when the session lock is busy, put the job back in the queue instead.
     *
     * @param  Closure(): mixed  $work
     */
    protected function releasingOnBusyLock(Closure $work): void
    {
        try {
            $work();
        } catch (SessionLockTimeoutException) {
            $this->release(self::LOCK_BUSY_RELEASE_SECONDS);
        }
    }
}
