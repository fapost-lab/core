<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;
use Fapost\Foundation\Lifecycle\AbstractConnectorServiceProvider;

/**
 * The fixture connector `echo` as the connector testing kit boots it (manifest in `kit-package/composer.json`): the
 * channel with its profile and webhook registrar, so the kit can create, register and configure a channel.
 */
final class EchoKitConnectorServiceProvider extends AbstractConnectorServiceProvider
{
    protected function registerChannels(ConnectorRegistrarInterface $registrar): void
    {
        $registrar->registerChannel(new ConnectorChannel(
            type: 'echo',
            sender: EchoSender::class,
            inbound: EchoInboundAdapter::class,
            webhookRegistrar: EchoWebhookRegistrar::class,
            profile: EchoChannelProfile::class,
        ));
    }
}
