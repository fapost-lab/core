<?php

declare(strict_types=1);

namespace Tests\Support\Flow;

use App\Domains\Flow\Webhook\WebhookReply;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;

/**
 * {@see WebhookReplyStoreInterface} in memory: an answer prepared for a fire key is "delivered" before the request
 * waits; the Redis one has its own test against a real Redis.
 */
final class InMemoryWebhookReplyStore implements WebhookReplyStoreInterface
{
    /** @var array<string, WebhookReply> by fire key */
    public array $prepared = [];

    /** @var list<array{string, string, string, int}> */
    public array $awaited = [];

    /** @var list<string> */
    public array $found = [];

    public function keep(string $tenantId, string $triggerId, string $fireKey, WebhookReply $reply): bool
    {
        if (isset($this->prepared[$fireKey])) {
            return false;
        }

        $this->prepared[$fireKey] = $reply;

        return true;
    }

    public function await(string $tenantId, string $triggerId, string $fireKey, int $seconds): ?WebhookReply
    {
        $this->awaited[] = [$tenantId, mb_strtolower($triggerId), $fireKey, $seconds];

        return $this->prepared[$fireKey] ?? null;
    }

    public function find(string $tenantId, string $triggerId, string $fireKey): ?WebhookReply
    {
        $this->found[] = $fireKey;

        return $this->prepared[$fireKey] ?? null;
    }
}
