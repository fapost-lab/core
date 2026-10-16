<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;

/**
 * Inbound half of the fixture channel: a shared secret in a header, the body's `id` as the idempotency key.
 */
final class EchoInboundAdapter implements ChannelInboundAdapterInterface
{
    public function ingressSpec(): IngressSpec
    {
        return IngressSpec::headerEquals('x-echo-secret', 'echo:{channel}:{body.id}');
    }

    public function normalize(array $payload): IncomingMessage
    {
        return new IncomingMessage(
            updateId: (string) ($payload['id'] ?? ''),
            externalUserId: (string) ($payload['from'] ?? ''),
            externalChatId: (string) ($payload['from'] ?? ''),
            text: isset($payload['text']) ? (string) $payload['text'] : null,
            type: IncomingMessageType::Text,
            platform: 'echo',
            payload: $payload,
        );
    }
}
