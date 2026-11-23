<?php

declare(strict_types=1);

namespace App\Domains\Flow\Admission;

use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use RuntimeException;
use Throwable;

/**
 * Counts a trigger's refused runs on its state row, writing the row at most once per {@see self::FLUSH_SECONDS}.
 *
 * A stream of refusals — a paused trigger hit by a bulk import's events — would otherwise write the same row once per
 * run. Each refusal is counted in the cache; the refusal that finds the trigger's write window free adds everything
 * counted so far to the row in one statement. What a window left in the cache is added by the next refusal after it,
 * and {@see self::pending()} lets a screen show it before then. When the cache cannot count the refusal, it is written
 * directly; once counted, a cache failure only delays its write to the next refusal.
 */
final readonly class TriggerRefusals
{
    public const int FLUSH_SECONDS = 10;

    /** How long counted refusals wait in the cache for the next write. */
    private const int PENDING_TTL_SECONDS = 2592000;

    public function __construct(
        private FlowTriggerStateRepositoryInterface $states,
        private Cache $cache,
        private ExceptionHandler $exceptions,
    ) {
    }

    public function record(string $tenantId, string $triggerId, RefusalReason $reason, DateTimeImmutable $at): void
    {
        $countKey = self::countKey($tenantId, $triggerId);

        try {
            // Made first with a lifetime: a store whose increment does not create a key (database, memcached) still
            // counts, and on Redis the counter does not outlive its purpose.
            $this->cache->add($countKey, 0, self::PENDING_TTL_SECONDS);

            if (false === $this->cache->increment($countKey)) {
                throw new RuntimeException('The cache did not count the refusal.');
            }
        } catch (Throwable $exception) {
            // Not counted in the cache: written directly, so it is not lost.
            $this->exceptions->report($exception);
            $this->states->addRefusals($triggerId, 1, $reason, $at);

            return;
        }

        try {
            $count = $this->takeIfDue($tenantId, $triggerId, $countKey, $reason, $at);
        } catch (Throwable $exception) {
            // Counted in the cache already: the next write takes it, so a direct write would count it twice.
            $this->exceptions->report($exception);
            $count = 0;
        }

        $this->states->addRefusals($triggerId, $count, $reason, $at);
    }

    /**
     * The refusals counted in the cache and not yet on the row, and the last of them.
     *
     * @return array{count: int, last_refused_at: string|null, last_refusal_reason: string|null}
     */
    public function pending(string $tenantId, string $triggerId): array
    {
        try {
            $count = (int) $this->cache->get(self::countKey($tenantId, $triggerId), 0);
            $last  = $count > 0 ? $this->cache->get(self::lastKey($tenantId, $triggerId)) : null;
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);

            return ['count' => 0, 'last_refused_at' => null, 'last_refusal_reason' => null];
        }

        return [
            'count'               => max(0, $count),
            'last_refused_at'     => is_array($last) && is_string($last['at'] ?? null) ? $last['at'] : null,
            'last_refusal_reason' => is_array($last) && is_string($last['reason'] ?? null) ? $last['reason'] : null,
        ];
    }

    private static function countKey(string $tenantId, string $triggerId): string
    {
        return 'flow-admission:refused:' . mb_strtolower($tenantId) . ':' . mb_strtolower($triggerId);
    }

    private static function lastKey(string $tenantId, string $triggerId): string
    {
        return self::countKey($tenantId, $triggerId) . ':last';
    }

    private static function windowKey(string $tenantId, string $triggerId): string
    {
        return self::countKey($tenantId, $triggerId) . ':window';
    }

    /**
     * Note the counted refusal as the last one; when the trigger's write window is free, take every refusal counted so
     * far.
     *
     * @return int how many refusals to write now; 0 inside a window
     */
    private function takeIfDue(string $tenantId, string $triggerId, string $countKey, RefusalReason $reason, DateTimeImmutable $at): int
    {
        $this->cache->put(
            self::lastKey($tenantId, $triggerId),
            ['at' => $at->format(DATE_ATOM), 'reason' => $reason->value],
            self::PENDING_TTL_SECONDS,
        );

        if (! $this->cache->add(self::windowKey($tenantId, $triggerId), true, self::FLUSH_SECONDS)) {
            return 0;
        }

        $taken = max(0, (int) $this->cache->get($countKey, 0));

        if ($taken > 0) {
            // A decrement, not a reset: refusals counted by other workers since the read stay for the next write.
            $this->cache->decrement($countKey, $taken);
        }

        return $taken;
    }
}
