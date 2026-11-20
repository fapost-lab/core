<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * {@see WebhookWaitSlotsInterface} in Redis: two sorted sets, `flow:webhook:wait-slots` for the installation and
 * `flow:webhook:wait-slots:{tenant}`, each member a slot scored by the moment it lapses.
 *
 * One Lua script prunes lapsed slots from both, checks both counts and adds the slot to both, or to neither. A counter
 * with a TTL would not do: every new slot would push the TTL out, and a slot leaked by a crashed request would never
 * lapse under steady traffic.
 */
final readonly class RedisWebhookWaitSlots implements WebhookWaitSlotsInterface
{
    public const string INSTALLATION_KEY = 'flow:webhook:wait-slots';

    /** KEYS: installation set, tenant set. ARGV: now (ms), lapse (ms), installation max, tenant max, slot. */
    private const string ACQUIRE = <<<'LUA'
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
        redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', ARGV[1])
        if redis.call('ZCARD', KEYS[1]) >= tonumber(ARGV[3]) or redis.call('ZCARD', KEYS[2]) >= tonumber(ARGV[4]) then
            return 0
        end
        redis.call('ZADD', KEYS[1], ARGV[2], ARGV[5])
        redis.call('ZADD', KEYS[2], ARGV[2], ARGV[5])
        local ttl = math.ceil((tonumber(ARGV[2]) - tonumber(ARGV[1])) / 1000) + 1
        redis.call('EXPIRE', KEYS[1], ttl)
        redis.call('EXPIRE', KEYS[2], ttl)
        return 1
        LUA;

    public function __construct(
        private RedisFactory $redis,
        private LoggerInterface $logger,
    ) {
    }

    public static function tenantKey(string $tenantId): string
    {
        return self::INSTALLATION_KEY . ':' . $tenantId;
    }

    public function acquire(string $tenantId, int $installationMax, int $tenantMax, int $holdSeconds): ?string
    {
        if ($installationMax < 1 || $tenantMax < 1) {
            return null;
        }

        $slot  = (string) Str::ulid();
        $nowMs = (int) floor(microtime(true) * 1000);

        try {
            $taken = (int) $this->redis->connection()->eval(
                self::ACQUIRE,
                2,
                self::INSTALLATION_KEY,
                self::tenantKey($tenantId),
                $nowMs,
                $nowMs + max(1, $holdSeconds) * 1000,
                $installationMax,
                $tenantMax,
                $slot,
            );
        } catch (Throwable $exception) {
            $this->unavailable('acquire', $tenantId, $exception);

            return null;
        }

        return 1 === $taken ? $slot : null;
    }

    public function release(string $tenantId, string $slot): void
    {
        try {
            $connection = $this->redis->connection();
            $connection->zrem(self::INSTALLATION_KEY, $slot);
            $connection->zrem(self::tenantKey($tenantId), $slot);
        } catch (Throwable $exception) {
            // The slot lapses on its own.
            $this->unavailable('release', $tenantId, $exception);
        }
    }

    private function unavailable(string $operation, string $tenantId, Throwable $exception): void
    {
        $this->logger->warning('flow.webhook.wait_slots_unavailable', [
            'operation' => $operation,
            'tenant_id' => $tenantId,
            'error'     => $exception->getMessage(),
        ]);
    }
}
