<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\DTOs\WebhookRegistryEntry;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use Illuminate\Support\Facades\Redis;
use JsonException;
use ValueError;

final class WebhookRegistryResolver implements WebhookRegistryResolverInterface
{
    /**
     * TTL in seconds for the thundering-herd leader lock.
     * Must be long enough for one DB round-trip, short enough to not stall retriers forever.
     */
    private const int LOCK_TTL_SECONDS = 5;

    public function __construct(
        private readonly WebhookRegistryReaderInterface $reader,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function resolve(string $hash): WebhookRegistryEntry
    {
        // 1. Redis hit — fast path, no DB involved
        $entry = $this->tryFromRedis($hash);
        if (null !== $entry) {
            return $entry;
        }

        // 2. Thundering herd: acquire a short-lived leader lock.
        //    Both leader and non-leader do their own DB lookup immediately — no sleep in the Octane hot path.
        //    Only the leader deletes the lock; non-leaders leave it for the leader to clean up.
        $lockKey  = "warming:{$hash}";
        $isLeader = (bool)Redis::set($lockKey, '1', 'EX', self::LOCK_TTL_SECONDS, 'NX');

        try {
            return $this->resolveFromLandlord($hash);
        } finally {
            if ($isLeader) {
                Redis::del($lockKey);
            }
        }
    }

    private function tryFromRedis(string $hash): ?WebhookRegistryEntry
    {
        $raw = Redis::get("webhook:{$hash}");

        if ( ! is_string($raw) || '' === $raw) {
            return null;
        }

        try {
            return WebhookRegistryEntry::fromRedis($raw);
        } catch (JsonException|ValueError) {
            return null;
        }
    }

    /**
     * @throws JsonException
     */
    private function resolveFromLandlord(string $hash): WebhookRegistryEntry
    {
        $row = $this->reader->findByHash($hash);

        if (null === $row) {
            throw new WebhookRegistryException("Webhook registry not found for hash: {$hash}");
        }

        $entry = WebhookRegistryEntry::fromLandlord($row);

        // Self-heal: restore Redis so subsequent requests skip DB entirely
        Redis::set("webhook:{$hash}", json_encode($entry->toRedisPayload(), JSON_THROW_ON_ERROR));

        return $entry;
    }
}
