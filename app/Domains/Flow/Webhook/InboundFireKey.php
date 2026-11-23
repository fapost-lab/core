<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use App\Domains\Flow\Api\ApiFireKey;

/**
 * Whether a run was started by an outside caller over HTTP — a webhook delivery (`wh:`) or an API call (`api:`) — and
 * so may have a caller waiting for its answer ({@see WebhookSyncWait}). Any other fire key (a schedule slot, an event,
 * a re-run) has nobody to answer.
 */
final class InboundFireKey
{
    public static function isInbound(string $fireKey): bool
    {
        return WebhookFireKey::isWebhookFireKey($fireKey) || ApiFireKey::isApiFireKey($fireKey);
    }
}
