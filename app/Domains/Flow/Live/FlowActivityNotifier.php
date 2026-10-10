<?php

declare(strict_types=1);

namespace App\Domains\Flow\Live;

use App\Domains\Flow\Models\FlowSession;
use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Announces that a flow session changed, as {@see FlowActivityChanged}, to whoever watches its assistant.
 *
 * Called on every session write, so it is cheap where it can be:
 *  - with no broadcaster configured (`null`, `log`) it returns before touching the cache, the queue or the transaction;
 *  - otherwise it waits for the write's transaction to commit (so the reload it causes sees the change, and the cache is
 *    not touched while the step holds its transaction), then announces only while a screen watches the assistant
 *    ({@see FlowActivityWatchers}) and at most once per {@see self::THROTTLE_SECONDS}. A screen that hears an
 *    announcement reloads at once and once more after that window, so a change swallowed by the throttle still shows.
 *
 * Never fatal: everything after the commit runs inside a `try`, so a failing cache, queue or broadcaster is reported and
 * neither fails (and retries) the committed flow step nor skips the transaction's other after-commit callbacks.
 */
final readonly class FlowActivityNotifier
{
    public const int THROTTLE_SECONDS = 2;

    public function __construct(
        private BroadcasterStatus $broadcaster,
        private FlowActivityWatchers $watchers,
        private Cache $cache,
        private Dispatcher $events,
        private ExceptionHandler $exceptions,
    ) {
    }

    public function touched(FlowSession $session): void
    {
        if (! $this->broadcaster->enabled()) {
            return;
        }

        $tenantId    = (string) $session->getAttribute('tenant_id');
        $assistantId = (string) $session->getAttribute('assistant_id');

        if ('' === $tenantId || '' === $assistantId) {
            return;
        }

        // Runs at once when no transaction is open.
        $session->getConnection()->afterCommit(function () use ($tenantId, $assistantId): void {
            $this->announce($tenantId, $assistantId);
        });
    }

    private function announce(string $tenantId, string $assistantId): void
    {
        try {
            if (! $this->watchers->isWatched($tenantId, $assistantId)) {
                return;
            }

            if ($this->cache->add("flow-activity:{$tenantId}:{$assistantId}", 1, self::THROTTLE_SECONDS)) {
                $this->events->dispatch(new FlowActivityChanged($tenantId, $assistantId));
            }
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }
}
