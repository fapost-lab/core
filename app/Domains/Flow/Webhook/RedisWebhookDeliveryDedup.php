<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * {@see WebhookDeliveryDedupInterface} in Redis: `SET NX EX` on `flow:webhook:delivery:{tenant}:{trigger}:{sha256(fire key)}`.
 *
 * A Redis failure is logged and the delivery let through: the session's unique fire key still stops a second run.
 */
final readonly class RedisWebhookDeliveryDedup implements WebhookDeliveryDedupInterface
{
    public function __construct(
        private RedisFactory $redis,
        private LoggerInterface $logger,
    ) {
    }

    public static function key(string $tenantId, string $triggerId, string $fireKey): string
    {
        return "flow:webhook:delivery:{$tenantId}:" . mb_strtolower($triggerId) . ':' . hash('sha256', $fireKey);
    }

    public function claim(string $tenantId, string $triggerId, string $fireKey): bool
    {
        try {
            return (bool) $this->redis->connection()->set(
                self::key($tenantId, $triggerId, $fireKey),
                '1',
                'EX',
                self::WINDOW_SECONDS,
                'NX',
            );
        } catch (Throwable $exception) {
            $this->logger->warning('flow.webhook.dedup_unavailable', [
                'tenant_id'  => $tenantId,
                'trigger_id' => $triggerId,
                'error'      => $exception->getMessage(),
            ]);

            return true;
        }
    }

    public function release(string $tenantId, string $triggerId, string $fireKey): void
    {
        try {
            $this->redis->connection()->del(self::key($tenantId, $triggerId, $fireKey));
        } catch (Throwable $exception) {
            $this->logger->warning('flow.webhook.dedup_unavailable', [
                'tenant_id'  => $tenantId,
                'trigger_id' => $triggerId,
                'error'      => $exception->getMessage(),
            ]);
        }
    }
}
