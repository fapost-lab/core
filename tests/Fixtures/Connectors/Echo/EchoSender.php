<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Messaging\ProviderSenderInterface;

/**
 * Outbound half of the fixture channel: delivers nothing and reports success.
 */
final class EchoSender implements ProviderSenderInterface
{
    public function deliver(OutboundMessage $message): DeliveryResult
    {
        return new DeliveryResult(sent: true, providerMessageId: 'echo-1');
    }
}
