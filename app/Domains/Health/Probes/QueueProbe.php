<?php

declare(strict_types=1);

namespace App\Domains\Health\Probes;

use App\Domains\Health\Contracts\HealthProbe;
use App\Domains\Health\Services\HorizonQueueMetrics;
use App\Domains\Health\ValueObjects\ProbeResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Reads Horizon's own metrics for the named queues: how long each would take to clear, how many jobs it has processed
 * per minute, and how many jobs failed recently.
 *
 * Horizon that is not running is "down", not "unknown": Redis answered and no Horizon instance (master supervisor) has reported in (or all of them
 * are paused), so queued jobs are certainly not being processed, which is the finding. A Redis that cannot be read says
 * nothing about the workers and is "unknown". A queue the running supervisors do not serve has no wait time; it is
 * reported as `null`, never as 0, because a made-up zero reads as an empty queue. The probe only reads: it never calls the
 * Horizon methods that stamp the snapshot time (see {@see HorizonQueueMetrics}).
 */
final readonly class QueueProbe implements HealthProbe
{
    public const string KEY = 'queues';

    /** Horizon's own default for the long-wait alert when `horizon.waits` has no entry for the queue. */
    private const int DEFAULT_WAIT_THRESHOLD = 60;

    private const int DEFAULT_FAILED_WINDOW_MINUTES = 10080;

    public function __construct(
        private Repository $config,
        private HorizonQueueMetrics $metrics,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function cacheTtl(): int
    {
        return 10;
    }

    public function check(): ProbeResult
    {
        $connection = (string) $this->config->get('queue.default');
        $driver     = (string) $this->config->get("queue.connections.{$connection}.driver");

        if ('redis' !== $driver) {
            return ProbeResult::disabled(
                self::KEY,
                "Queues run on the \"{$connection}\" connection, not through Horizon, so there are no queue metrics to read.",
                ['connection' => $connection, 'driver' => $driver],
            );
        }

        try {
            $masters = $this->metrics->masters();
            $details = $this->details(count($masters));
        } catch (Throwable $exception) {
            return ProbeResult::unknown(
                self::KEY,
                'Horizon\'s metrics could not be read from Redis (' . $exception::class . ').',
            );
        }

        if ([] === $masters) {
            return ProbeResult::down(
                self::KEY,
                'Horizon is not running: no Horizon instance has reported in, so queued jobs are not being processed.',
                $details,
            );
        }

        if ([] === array_filter($masters, static fn (string $status): bool => 'paused' !== $status)) {
            return ProbeResult::down(
                self::KEY,
                'Horizon is paused: its instances are up but taking no jobs, so queued jobs are not being processed.',
                $details,
            );
        }

        return ProbeResult::up(
            self::KEY,
            'Horizon is running (' . count($masters) . (1 === count($masters) ? ' instance).' : ' instances).'),
            $details,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function details(int $masters): array
    {
        $waits          = $this->metrics->waits();
        $lastSnapshotAt = $this->metrics->lastSnapshotAt();
        $minutes        = null === $lastSnapshotAt
            ? null
            : max((CarbonImmutable::now()->getTimestamp() - $lastSnapshotAt) / 60, 1);

        // Read as a whole array: the keys ("redis:flow.execution") contain dots, which dot-notation would split.
        $thresholds = (array) $this->config->get('horizon.waits', []);
        $queues     = [];

        foreach ($this->queues($waits) as ['connection' => $connection, 'name' => $name]) {
            $throughput = $this->metrics->throughput($name);

            $queues[] = [
                'name'            => $name,
                'connection'      => $connection,
                'wait_seconds'    => $waits["{$connection}:{$name}"] ?? null,
                'wait_threshold'  => (int) ($thresholds["{$connection}:{$name}"] ?? self::DEFAULT_WAIT_THRESHOLD),
                'throughput'      => $throughput,
                'jobs_per_minute' => null === $minutes ? null : round($throughput / $minutes, 1),
            ];
        }

        return [
            'masters'               => $masters,
            'failed'                => $this->metrics->recentlyFailed(),
            'failed_window_minutes' => (int) $this->config->get('horizon.trim.recent_failed', self::DEFAULT_FAILED_WINDOW_MINUTES),
            'window_started_at'     => null === $lastSnapshotAt ? null : CarbonImmutable::createFromTimestamp($lastSnapshotAt)->toIso8601String(),
            'queues'                => $queues,
        ];
    }

    /**
     * The named queues the supervisors are configured for, in config order, then any the wait calculator knows of that
     * the config does not name. A key that holds several comma-joined queues is a single supervisor pool and stays one name.
     *
     * @param array<string, int> $waits
     *
     * @return list<array{connection: string, name: string}>
     */
    private function queues(array $waits): array
    {
        $found = [];

        foreach ((array) $this->config->get('horizon.defaults', []) as $supervisor) {
            $supervisor = (array) $supervisor;
            $connection = (string) ($supervisor['connection'] ?? 'redis');

            foreach ((array) ($supervisor['queue'] ?? []) as $name) {
                $found["{$connection}:{$name}"] = ['connection' => $connection, 'name' => (string) $name];
            }
        }

        foreach (array_keys($waits) as $key) {
            if (! isset($found[$key]) && str_contains($key, ':')) {
                [$connection, $name] = explode(':', $key, 2);
                $found[$key]         = ['connection' => $connection, 'name' => $name];
            }
        }

        return array_values($found);
    }
}
