<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Messaging\Exceptions\RateLimitExceededException;
use App\Domains\Messaging\Exceptions\UnsupportedChannelException;
use FAPost\Foundation\Messaging\DeliveryResult;
use FAPost\Foundation\Messaging\MessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Generic outbound messaging engine.
 *
 * Applies idempotency and rate limiting before delegating delivery to the
 * channel-specific sender resolved from the shared channel registry.
 */
final readonly class MessageSender implements MessageSenderInterface
{
    /**
     * @param  int  $rateLimitPerMinute  Per-channel-chat rate limit enforced in Redis.
     */
    public function __construct(
        private ChannelRegistryInterface $channelRegistry,
        private RedisFactory $redis,
        private int $rateLimitPerMinute,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function send(OutboundMessage $message): DeliveryResult
    {
        if ( ! $this->reserveIdempotency($message->idempotencyKey)) {
            return new DeliveryResult(sent: false, duplicate: true);
        }

        if ($this->isRateLimitExceeded($message->channelId, $message->chatId)) {
            $this->releaseIdempotency($message->idempotencyKey);
            throw new RateLimitExceededException('Channel rate limit exceeded.');
        }

        $provider = $this->channelRegistry->sender($message->channelType);

        if (null === $provider) {
            $this->releaseIdempotency($message->idempotencyKey);
            throw new UnsupportedChannelException("Unsupported channel type [{$message->channelType}].");
        }

        try {
            $result = $provider->deliver($message);
        } catch (Throwable $exception) {
            $this->releaseIdempotency($message->idempotencyKey);
            throw $exception;
        }

        if ($result->sent) {
            $this->markSent($message->idempotencyKey);
        } else {
            $this->releaseIdempotency($message->idempotencyKey);
        }

        return $result;
    }

    /**
     * Try to reserve the message idempotency key for the current delivery attempt.
     */
    private function reserveIdempotency(string $idempotencyKey): bool
    {
        return (bool) $this->redis->connection()->set(
            $this->idempotencyKey($idempotencyKey),
            'processing',
            'EX',
            86400,
            'NX',
        );
    }

    /**
     * Mark the idempotency key as successfully delivered.
     */
    private function markSent(string $idempotencyKey): void
    {
        $this->redis->connection()->set(
            $this->idempotencyKey($idempotencyKey),
            '1',
            'EX',
            86400,
        );
    }

    /**
     * Release the idempotency reservation after an unsuccessful attempt.
     */
    private function releaseIdempotency(string $idempotencyKey): void
    {
        $this->redis->connection()->del($this->idempotencyKey($idempotencyKey));
    }

    /**
     * Increment the per-recipient rate counter and report whether the limit was exceeded.
     *
     * INCR and EXPIRE are sent in a single pipeline to prevent TTL loss on connection failure.
     */
    private function isRateLimitExceeded(string $channelId, string $chatId): bool
    {
        $key = "rate:{$channelId}:{$chatId}";

        /** @var array{int, mixed} $results */
        $results = $this->redis->connection()->pipeline(function ($pipe) use ($key): void {
            $pipe->incr($key);
            $pipe->expire($key, 60);
        });

        return ((int) $results[0]) > $this->rateLimitPerMinute;
    }

    /**
     * Build the Redis key used to track message delivery idempotency.
     */
    private function idempotencyKey(string $idempotencyKey): string
    {
        return "msg:sent:{$idempotencyKey}";
    }
}
