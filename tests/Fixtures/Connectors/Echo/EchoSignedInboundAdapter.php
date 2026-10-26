<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\InboundEventIgnoredException;
use Fapost\Foundation\Channel\Ingress\IngressHandshake;
use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Fapost\Foundation\Channel\Ingress\IngressTimestamp;
use Fapost\Foundation\Channel\Ingress\SignatureScheme;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;

/**
 * An inbound half that uses every capability of ingress version 2: a signature over `v0:{timestamp}:{raw}` within five
 * minutes, a JSON document that may arrive in a form field, a challenge answered in plain text and an idempotency key
 * with an alternative. It ignores a delivery marked `bot`.
 */
final class EchoSignedInboundAdapter implements ChannelInboundAdapterInterface
{
    public function ingressSpec(): IngressSpec
    {
        return new IngressSpec(
            scheme: SignatureScheme::HmacSha256,
            parameter: 'x-echo-signature',
            prefix: 'v0=',
            idempotencyTemplate: 'echo:{channel}:{body.event_id|body.trigger_id}',
            signedPayload: 'v0:{header.x-echo-timestamp}:{raw}',
            timestamp: new IngressTimestamp('x-echo-timestamp', 300),
            formField: 'payload',
            handshake: new IngressHandshake('{body.type}', 'url_verification', '{body.challenge}'),
        );
    }

    public function normalize(array $payload): IncomingMessage
    {
        if (true === ($payload['bot'] ?? false)) {
            throw new InboundEventIgnoredException('The delivery is the bot\'s own message.');
        }

        return new IncomingMessage(
            updateId: (string) ($payload['event_id'] ?? ''),
            externalUserId: (string) ($payload['from'] ?? ''),
            externalChatId: (string) ($payload['from'] ?? ''),
            text: isset($payload['text']) ? (string) $payload['text'] : null,
            type: IncomingMessageType::Text,
            platform: 'echo',
            payload: $payload,
        );
    }
}
