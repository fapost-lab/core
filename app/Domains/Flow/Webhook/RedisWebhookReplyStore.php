<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * {@see WebhookReplyStoreInterface} in Redis.
 *
 * The answer is `SET NX EX` on `flow:webhook:reply:{tenant}:{trigger}:{sha256(fire key)}`; only the write that wins
 * pushes a wake-up onto the list `…:wake` (same TTL). The waiting request blocks on that list (`BLPOP`) and then reads
 * the answer key — the key is the truth, the list only wakes. An answer delivered before the request starts waiting
 * (a fast worker, or a sync queue) leaves its wake-up in the list, so the wait returns at once.
 *
 * Every Redis failure is logged and taken as "no answer": the sender gets 202, the run is untouched.
 */
final readonly class RedisWebhookReplyStore implements WebhookReplyStoreInterface
{
    public function __construct(
        private RedisFactory $redis,
        private LoggerInterface $logger,
    ) {
    }

    public static function key(string $tenantId, string $triggerId, string $fireKey): string
    {
        return "flow:webhook:reply:{$tenantId}:" . mb_strtolower($triggerId) . ':' . hash('sha256', $fireKey);
    }

    public static function wakeKey(string $tenantId, string $triggerId, string $fireKey): string
    {
        return self::key($tenantId, $triggerId, $fireKey) . ':wake';
    }

    public function keep(string $tenantId, string $triggerId, string $fireKey, WebhookReply $reply): bool
    {
        try {
            $connection = $this->redis->connection();

            $kept = (bool) $connection->set(self::key($tenantId, $triggerId, $fireKey), $reply->toJson(), 'EX', self::TTL_SECONDS, 'NX');

            if ($kept) {
                $wake = self::wakeKey($tenantId, $triggerId, $fireKey);
                $connection->rpush($wake, '1');
                $connection->expire($wake, self::TTL_SECONDS);
            }

            return $kept;
        } catch (Throwable $exception) {
            $this->unavailable('keep', $tenantId, $triggerId, $exception);

            return false;
        }
    }

    public function await(string $tenantId, string $triggerId, string $fireKey, int $seconds): ?WebhookReply
    {
        try {
            // An integer timeout: Redis before 6 takes no fraction, and 0 would block forever.
            $this->redis->connection()->blpop([self::wakeKey($tenantId, $triggerId, $fireKey)], max(1, $seconds));
        } catch (Throwable $exception) {
            $this->unavailable('await', $tenantId, $triggerId, $exception);

            return null;
        }

        return $this->find($tenantId, $triggerId, $fireKey);
    }

    public function find(string $tenantId, string $triggerId, string $fireKey): ?WebhookReply
    {
        try {
            $json = $this->redis->connection()->get(self::key($tenantId, $triggerId, $fireKey));
        } catch (Throwable $exception) {
            $this->unavailable('find', $tenantId, $triggerId, $exception);

            return null;
        }

        return is_string($json) ? WebhookReply::fromJson($json) : null;
    }

    private function unavailable(string $operation, string $tenantId, string $triggerId, Throwable $exception): void
    {
        $this->logger->warning('flow.webhook.reply_store_unavailable', [
            'operation'  => $operation,
            'tenant_id'  => $tenantId,
            'trigger_id' => $triggerId,
            'error'      => $exception->getMessage(),
        ]);
    }
}
