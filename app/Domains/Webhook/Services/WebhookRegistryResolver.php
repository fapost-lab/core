<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\DTOs\WebhookRegistryEntry;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use Illuminate\Support\Facades\DB;
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

    /**
     * Microseconds between Redis retry attempts for non-leader requests.
     */
    private const int RETRY_INTERVAL_US = 200_000;

    /**
     * Maximum retry attempts for non-leader requests waiting for cache warm-up.
     */
    private const int MAX_RETRIES = 4;

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

        // 2. Thundering herd protection: only one request does DB lookup per hash
        $lockKey  = "warming:{$hash}";
        $isLeader = (bool) Redis::set($lockKey, '1', 'EX', self::LOCK_TTL_SECONDS, 'NX');

        if ( ! $isLeader) {
            // Non-leader: wait for the leader to populate Redis
            for ($i = 0; $i < self::MAX_RETRIES; $i++) {
                usleep(self::RETRY_INTERVAL_US);
                $entry = $this->tryFromRedis($hash);
                if (null !== $entry) {
                    return $entry;
                }
            }
            // Leader did not populate (slow or failed) — fall through to own DB lookup
        }

        try {
            return $this->resolveFromLandlord($hash);
        } finally {
            Redis::del($lockKey);
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
        $row = DB::connection('landlord')
            ->table('webhook_registry')
            ->where('webhook_public_hash', $hash)
            ->first();

        if (null === $row) {
            throw new WebhookRegistryException("Webhook registry not found for hash: {$hash}");
        }

        $entry = WebhookRegistryEntry::fromLandlord($row);

        // Self-heal: restore Redis so subsequent requests skip DB entirely
        Redis::set("webhook:{$hash}", json_encode([
            'tenant_id'    => $entry->tenantId,
            'assistant_id' => $entry->assistantId,
            'channel_id'   => $entry->channelId,
            'schema'       => $entry->schema,
            'channel'      => $entry->platform->value,
            'secret_token' => $entry->secretToken,
        ], JSON_THROW_ON_ERROR));

        return $entry;
    }
}
