<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;
use Fapost\Foundation\Lifecycle\AbstractConnectorServiceProvider;

/**
 * The provider of the fixture connector `echo`. Registers one channel, `echo`, with a sender, an inbound adapter and a
 * media link policy.
 */
final class EchoConnectorServiceProvider extends AbstractConnectorServiceProvider
{
    protected function registerChannels(ConnectorRegistrarInterface $registrar): void
    {
        $registrar->registerChannel(new ConnectorChannel(
            type: 'echo',
            sender: EchoSender::class,
            inbound: EchoInboundAdapter::class,
            mediaLinkPolicy: EchoMediaLinkPolicy::class,
        ));
    }
}
