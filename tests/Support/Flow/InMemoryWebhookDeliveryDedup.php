<?php

declare(strict_types=1);

namespace Tests\Support\Flow;

use App\Domains\Flow\Webhook\WebhookDeliveryDedupInterface;

/**
 * {@see WebhookDeliveryDedupInterface} in memory: the Redis one has its own test against a real Redis.
 */
final class InMemoryWebhookDeliveryDedup implements WebhookDeliveryDedupInterface
{
    /** @var array<string, true> */
    public array $claimed = [];

    public function claim(string $tenantId, string $triggerId, string $fireKey): bool
    {
        $key = "{$tenantId}:{$triggerId}:{$fireKey}";

        if (isset($this->claimed[$key])) {
            return false;
        }

        $this->claimed[$key] = true;

        return true;
    }

    public function release(string $tenantId, string $triggerId, string $fireKey): void
    {
        unset($this->claimed["{$tenantId}:{$triggerId}:{$fireKey}"]);
    }
}
