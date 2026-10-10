<?php

declare(strict_types=1);

namespace App\Domains\Staff\Listeners;

use App\Domains\Staff\Jobs\SendLimitNoticeJob;
use App\Domains\Staff\Support\LimitEpisode;
use App\Domains\Tenancy\Events\LimitReached;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Starts the notification of a tenant's admins about a limit, once per limit and episode.
 *
 * Runs synchronously on the path that was just refused work, so it does one atomic `add` on the cache
 * and, only for the first refusal of an episode, queues {@see SendLimitNoticeJob}. It never throws: a
 * broken cache or queue is reported and the limit decision stays what it was.
 */
final readonly class NotifyAdminsOfLimit
{
    public function __construct(
        private Repository $cache,
        private Dispatcher $bus,
    ) {
    }

    public function handle(LimitReached $event): void
    {
        $episode = null;

        try {
            $episode = LimitEpisode::of($event);

            if (! $this->cache->add($episode->cacheKey, true, $episode->expiresAt)) {
                return;
            }

            $this->bus->dispatch(SendLimitNoticeJob::for($event, $episode->cacheKey));
        } catch (Throwable $exception) {
            report($exception);

            $this->release($episode);
        }
    }

    /**
     * Lets the next refusal try again when the job could not be queued.
     */
    private function release(?LimitEpisode $episode): void
    {
        if (null === $episode) {
            return;
        }

        try {
            $this->cache->forget($episode->cacheKey);
        } catch (Throwable) {
            // The cache is the broken part; nothing more to do.
        }
    }
}
