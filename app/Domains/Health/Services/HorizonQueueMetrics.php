<?php

declare(strict_types=1);

namespace App\Domains\Health\Services;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\WaitTimeCalculator;

/**
 * The reads of Horizon's state the queue probe needs, and nothing it could write.
 *
 * Horizon's own `MetricsRepository::jobsProcessedPerMinute()` and `minutesSinceLastSnapshot()` stamp `last_snapshot_at`
 * when they run, which would move the window that `horizon:snapshot` closes every time somebody opened the health page.
 * So the timestamp is read as a plain key and the per-minute figure is worked out by the caller. Every method throws
 * whatever the Redis client throws; the probe turns that into "unknown".
 */
final readonly class HorizonQueueMetrics
{
    private const string SNAPSHOT_KEY = 'last_snapshot_at';

    public function __construct(
        private MasterSupervisorRepository $masters,
        private WaitTimeCalculator $waitTimes,
        private MetricsRepository $metrics,
        private JobRepository $jobs,
        private RedisFactory $redis,
    ) {
    }

    /**
     * The status (`running` or `paused`) of every master supervisor that has reported in.
     *
     * @return list<string>
     */
    public function masters(): array
    {
        return array_values(array_map(
            static fn (object $master): string => (string) ($master->status ?? ''),
            $this->masters->all(),
        ));
    }

    /**
     * Seconds to clear each queue that a running supervisor serves, keyed `connection:queue`.
     *
     * @return array<string, int>
     */
    public function waits(): array
    {
        return array_map(
            static fn (mixed $seconds): int => (int) round((float) $seconds),
            $this->waitTimes->calculate(),
        );
    }

    /**
     * Jobs processed on the queue since the last snapshot.
     */
    public function throughput(string $queue): int
    {
        return (int) $this->metrics->throughputForQueue($queue);
    }

    /**
     * When `horizon:snapshot` last ran, as a Unix timestamp; null when it never has.
     */
    public function lastSnapshotAt(): ?int
    {
        $value = $this->redis->connection('horizon')->get(self::SNAPSHOT_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Failed jobs inside Horizon's recent-failed window.
     */
    public function recentlyFailed(): int
    {
        return (int) $this->jobs->countRecentlyFailed();
    }
}
