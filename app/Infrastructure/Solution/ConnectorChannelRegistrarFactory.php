<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Channels\ConnectorChannels;
use App\Domains\Solution\Contracts\ConnectorRegistrarFactoryInterface;
use App\Domains\Solution\DTO\InstalledConnector;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Gives each connector a registrar of its own, so what it registers is checked against its own manifest.
 */
final readonly class ConnectorChannelRegistrarFactory implements ConnectorRegistrarFactoryInterface
{
    public function __construct(
        private ConnectorChannels $channels,
        private Container $container,
    ) {
    }

    public function forConnector(InstalledConnector $connector): ConnectorRegistrarInterface
    {
        return new ConnectorChannelRegistrar($connector, $this->channels, $this->container);
    }

    public function freeze(): void
    {
        $this->channels->freeze();
    }
}
