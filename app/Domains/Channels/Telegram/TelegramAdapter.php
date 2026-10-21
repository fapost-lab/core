<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Fapost\Foundation\DTO\IncomingMessage;

/**
 * Telegram implementation of the inbound adapter contract.
 *
 * Ingress is declarative: Telegram signs nothing, it echoes back the secret token that was supplied to setWebhook, so
 * {@see self::ingressSpec()} is the whole check and the idempotency key, executed by Core and the ingress gateway
 * alike. The worker method (normalize) parses the full payload via TelegramInboundNormalizer.
 */
final readonly class TelegramAdapter implements ChannelInboundAdapterInterface
{
    private const string SECRET_HEADER = 'x-telegram-bot-api-secret-token';

    public function __construct(
        private TelegramInboundNormalizer $normalizer,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function ingressSpec(): IngressSpec
    {
        return IngressSpec::headerEquals(
            header: self::SECRET_HEADER,
            idempotencyTemplate: 'tg:{channel}:{body.update_id}',
        );
    }

    /**
     * Normalize the decoded Telegram webhook payload into a platform-agnostic IncomingMessage.
     *
     * Called only from IncomingMessageJob (worker), never from ingress.
     *
     * @param  array<string, mixed>  $payload
     */
    public function normalize(array $payload): IncomingMessage
    {
        return $this->normalizer->normalize($payload);
    }
}
