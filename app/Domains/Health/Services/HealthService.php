<?php

declare(strict_types=1);

namespace App\Domains\Health\Services;

use App\Domains\Health\Contracts\HealthProbe;
use App\Domains\Health\ValueObjects\HealthReport;
use App\Domains\Health\ValueObjects\ProbeResult;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * The one place that answers "is it working" for the installation's services.
 *
 * Read-only, and the single source for every consumer: the console page, the contract the monitoring package reads and
 * `gateway:doctor`, so that a check exists once. It hands back installation-wide, unfiltered data; narrowing it to
 * what a tenant may see is the consumer's job.
 *
 * Each probe's answer is cached for the few seconds it declares, so a page polling every second asks the monitored
 * services once per window instead of once per viewer. The cache is a courtesy, never a dependency: a cache that cannot
 * be read or written means the probe runs live.
 */
final readonly class HealthService
{
    private const string CACHE_PREFIX = 'health:probe:';

    /**
     * @param iterable<HealthProbe> $probes
     */
    public function __construct(
        private iterable $probes,
        private Cache $cache,
    ) {
    }

    public function report(): HealthReport
    {
        $results = [];

        foreach ($this->probes as $probe) {
            $results[$probe->key()] = $this->resultOf($probe);
        }

        return new HealthReport($results);
    }

    /**
     * One probe's answer, or null when no probe has that key.
     */
    public function probe(string $key): ?ProbeResult
    {
        foreach ($this->probes as $probe) {
            if ($probe->key() === $key) {
                return $this->resultOf($probe);
            }
        }

        return null;
    }

    private function resultOf(HealthProbe $probe): ProbeResult
    {
        $cached = $this->cached($probe);

        if (null !== $cached) {
            return $cached;
        }

        try {
            $result = $probe->check();
        } catch (Throwable $throwable) {
            // A probe that fails here has not answered; that is "unknown", never a default of healthy.
            $result = ProbeResult::unknown($probe->key(), 'The probe failed: ' . $throwable::class . '.');
        }

        $this->remember($probe, $result);

        return $result;
    }

    private function cached(HealthProbe $probe): ?ProbeResult
    {
        try {
            $stored = $this->cache->get(self::CACHE_PREFIX . $probe->key());

            return is_array($stored) ? ProbeResult::fromArray($stored) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function remember(HealthProbe $probe, ProbeResult $result): void
    {
        try {
            $this->cache->put(self::CACHE_PREFIX . $probe->key(), $result->toArray(), $probe->cacheTtl());
        } catch (Throwable) {
            // Served live next time.
        }
    }
}
