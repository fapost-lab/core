<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Messaging\Exceptions\ChannelNotFoundException;
use App\Domains\Messaging\Exceptions\RateLimitExceededException;
use App\Domains\Messaging\Exceptions\UnsupportedChannelException;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Generic outbound messaging engine.
 *
 * Applies idempotency and rate limiting before delegating delivery to the
 * channel-specific sender resolved from the shared channel registry. The tenant's
 * outbound volume is spent just before delivery ({@see OutboundVolumeGate}).
 *
 * The envelope carries no secret: the channel is loaded here, in the current tenant context, and handed
 * to the sender, which reads the credentials from it for the duration of the call.
 */
final readonly class MessageSender implements MessageSenderInterface
{
    /**
     * @param  TenantMessagingRateLimit  $rateLimit  Per-channel-chat rate limit of the current tenant, enforced in Redis.
     */
    public function __construct(
        private ChannelRegistryInterface $channelRegistry,
        private RedisFactory $redis,
        private TenantMessagingRateLimit $rateLimit,
        private ConversationLoggerInterface $conversationLogger,
        private ConversationCaptureFactory $captureFactory,
        private OutboundVolumeGate $volumeGate,
    ) {
    }

    /**
     * @throws ChannelNotFoundException When the channel no longer exists; nothing is spent or delivered.
     * @throws Throwable
     */
    public function send(OutboundMessage $message): DeliveryResult
    {
        if (! $this->reserveIdempotency($message->idempotencyKey)) {
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

        $channel = Channel::query()->find($message->channelId);

        if (null === $channel) {
            $this->releaseIdempotency($message->idempotencyKey);
            throw new ChannelNotFoundException("Channel [{$message->channelId}] not found.");
        }

        // Last check before the side effect: a duplicate or a failed precondition above never spends a unit.
        try {
            $this->volumeGate->admit($message);
        } catch (Throwable $exception) {
            $this->releaseIdempotency($message->idempotencyKey);
            throw $exception;
        }

        try {
            $result = $provider->deliver($message, $channel);
        } catch (Throwable $exception) {
            $this->releaseIdempotency($message->idempotencyKey);
            throw $exception;
        }

        if ($result->sent) {
            $this->markSent($message->idempotencyKey);
            $this->captureTranscript($message, $result);
        } else {
            $this->releaseIdempotency($message->idempotencyKey);
        }

        return $result;
    }

    /**
     * Record the delivered message in the conversation transcript. This is the
     * single outbound capture site — every flow / notify / broadcast delivery
     * funnels through here (spec §7.2). Only messages whose builder supplied the
     * capture context (contact/assistant identity in metadata) are logged; the
     * logger itself never throws back into delivery.
     */
    private function captureTranscript(OutboundMessage $message, DeliveryResult $result): void
    {
        $entry = $this->captureFactory->forOutbound($message, $result);

        if (null !== $entry) {
            $this->conversationLogger->log($entry);
        }
    }

    /**
     * Try to reserve the message idempotency key for the current delivery attempt.
     */
    private function reserveIdempotency(string $idempotencyKey): bool
    {
        return (bool)$this->redis->connection()->set(
            $this->idempotencyKey($idempotencyKey),
            'processing',
            'EX',
            86400,
            'NX',
        );
    }

    /**
     * Build the Redis key used to track message delivery idempotency.
     */
    private function idempotencyKey(string $idempotencyKey): string
    {
        return "msg:sent:{$idempotencyKey}";
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

        return ((int)$results[0]) > $this->rateLimit->perMinute();
    }

    /**
     * Release the idempotency reservation after an unsuccessful attempt.
     */
    private function releaseIdempotency(string $idempotencyKey): void
    {
        $this->redis->connection()->del($this->idempotencyKey($idempotencyKey));
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
}
