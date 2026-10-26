<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use App\Domains\Channels\ConnectorChannels;
use Fapost\Testing\ConnectorTestCase;
use Tests\Fixtures\Connectors\Echo\EchoKitConnectorServiceProvider;

/**
 * `bootConnector()` of a connector that Core has already discovered and booted (the package installed through the
 * overlay): the first call below stands in for discovery, and the kit must then not register the connector again.
 */
final class ConnectorTestCaseDiscoveredBootTest extends ConnectorTestCase
{
    private const string COMPOSER = __DIR__ . '/../../Fixtures/Connectors/Echo/kit-package/composer.json';

    public function test_booting_an_already_discovered_connector_does_not_register_it_twice(): void
    {
        $this->bootConnector(EchoKitConnectorServiceProvider::class, self::COMPOSER);

        $owner = $this->app->make(ConnectorChannels::class)->ownerOf('echo');

        $this->bootConnector(EchoKitConnectorServiceProvider::class, self::COMPOSER);

        $this->assertSame($owner, $this->app->make(ConnectorChannels::class)->ownerOf('echo'));
        $this->assertCount(1, $this->app->getProviders(EchoKitConnectorServiceProvider::class));
    }

    public function test_a_discovered_connector_is_still_checked_against_its_manifest(): void
    {
        $this->bootConnector(EchoKitConnectorServiceProvider::class, self::COMPOSER);

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);

        $this->bootConnector(\Tests\Fixtures\Connectors\Echo\EchoConnectorServiceProvider::class, self::COMPOSER);
    }
}
