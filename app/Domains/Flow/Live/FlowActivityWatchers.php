<?php

declare(strict_types=1);

namespace App\Domains\Flow\Live;

use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Whether anybody is watching an assistant's flow activity, so that nothing is announced to an empty room.
 *
 * A live screen marks its assistant as watched each time it renders or reloads, and when its channel is authorized; the
 * mark lasts {@see self::TTL_SECONDS}. A screen on the websocket reloads at least every
 * `useLiveUpdates` heartbeat (shorter than the TTL), so the mark holds while the screen is open and lapses soon after it
 * closes. Only marked while a broadcaster delivers: with polling there is nothing to announce.
 */
final readonly class FlowActivityWatchers
{
    public const int TTL_SECONDS = 300;

    public function __construct(
        private BroadcasterStatus $broadcaster,
        private Cache $cache,
    ) {
    }

    public function watch(string $tenantId, string $assistantId): void
    {
        if ($this->broadcaster->enabled()) {
            $this->cache->put($this->key($tenantId, $assistantId), 1, self::TTL_SECONDS);
        }
    }

    public function isWatched(string $tenantId, string $assistantId): bool
    {
        return $this->cache->has($this->key($tenantId, $assistantId));
    }

    private function key(string $tenantId, string $assistantId): string
    {
        return "flow-activity-watched:{$tenantId}:{$assistantId}";
    }
}
