<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

/**
 * Hands a flow's answer to the webhook request that waits for it (`response_mode: sync`), keyed by the run's trigger
 * and fire key.
 *
 * The first answer of a run wins: a `webhook_response` node, a repeat of its step, and the end of the run may all
 * deliver, and only the first is kept. An answer lives for {@see self::TTL_SECONDS}, so one nobody waits for — an async
 * trigger, a wait that already gave up — simply expires. A store that cannot answer loses the answer, never the run:
 * the sender gets 202.
 */
interface WebhookReplyStoreInterface
{
    /** How long a delivered answer, and its wake-up, are kept. Longer than the longest wait. */
    public const int TTL_SECONDS = 60;

    /**
     * Keeps the run's answer unless one is already kept, and wakes the request waiting for it.
     *
     * @return bool true when this answer is the one kept
     */
    public function keep(string $tenantId, string $triggerId, string $fireKey, WebhookReply $reply): bool;

    /**
     * Waits up to `$seconds` for the run's answer; null when none came in time or the store failed.
     */
    public function await(string $tenantId, string $triggerId, string $fireKey, int $seconds): ?WebhookReply;

    /**
     * The run's answer if one is kept now, without waiting: what a repeated delivery is answered while it lives.
     */
    public function find(string $tenantId, string $triggerId, string $fireKey): ?WebhookReply;
}
