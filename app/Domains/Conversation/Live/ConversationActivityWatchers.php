<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Live;

use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Whether anybody is watching an assistant's conversations, so that nothing is announced to an empty room.
 *
 * The same mark as {@see \App\Domains\Flow\Live\FlowActivityWatchers}: set each time an inbox screen renders or reloads
 * and when its channel is authorized, lasting {@see self::TTL_SECONDS} (longer than the `useLiveUpdates` heartbeat), and
 * only while a broadcaster delivers.
 */
final readonly class ConversationActivityWatchers
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
        return "conversation-activity-watched:{$tenantId}:{$assistantId}";
    }
}
