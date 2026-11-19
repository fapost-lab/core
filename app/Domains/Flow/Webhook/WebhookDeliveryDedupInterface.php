<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

/**
 * The fast first layer of webhook deduplication: a delivery claimed once is refused for a day. The final guarantee is
 * the unique `(trigger_id, fire_key)` of the run's session, which {@see \App\Domains\Flow\Orchestration\AutomationRunner}
 * continues rather than repeats.
 */
interface WebhookDeliveryDedupInterface
{
    public const int WINDOW_SECONDS = 86400;

    /**
     * Claims the delivery: true the first time within the window, false for a repeat. A store that cannot answer
     * answers true, so a delivery is never lost to it.
     */
    public function claim(string $tenantId, string $triggerId, string $fireKey): bool;

    /**
     * Gives a claim back, when the run could not be queued, so the sender's retry is not taken for a duplicate.
     */
    public function release(string $tenantId, string $triggerId, string $fireKey): void;
}
