<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Live;

use App\Domains\Conversation\Models\Conversation;
use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Announces that an assistant's conversations changed, as {@see ConversationActivityChanged}, to whoever watches them.
 *
 * Works like {@see \App\Domains\Flow\Live\FlowActivityNotifier}: with no delivering broadcaster it returns at once;
 * otherwise it waits for the write's transaction to commit, announces only while an inbox screen watches the assistant
 * and at most once per {@see self::THROTTLE_SECONDS}, and reports any failure instead of throwing it into the
 * transcript job or the operator's request.
 */
final readonly class ConversationActivityNotifier
{
    public const int THROTTLE_SECONDS = 2;

    public function __construct(
        private BroadcasterStatus $broadcaster,
        private ConversationActivityWatchers $watchers,
        private Cache $cache,
        private Dispatcher $events,
        private ExceptionHandler $exceptions,
    ) {
    }

    public function touched(string $tenantId, string $assistantId): void
    {
        if (! $this->broadcaster->enabled() || '' === $tenantId || '' === $assistantId) {
            return;
        }

        // Runs at once when no transaction is open.
        (new Conversation())->getConnection()->afterCommit(function () use ($tenantId, $assistantId): void {
            $this->announce($tenantId, $assistantId);
        });
    }

    private function announce(string $tenantId, string $assistantId): void
    {
        try {
            if (! $this->watchers->isWatched($tenantId, $assistantId)) {
                return;
            }

            if ($this->cache->add("conversation-activity:{$tenantId}:{$assistantId}", 1, self::THROTTLE_SECONDS)) {
                $this->events->dispatch(new ConversationActivityChanged($tenantId, $assistantId));
            }
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }
}
