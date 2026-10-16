<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Channels\ChannelRegistry;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Contracts\ActionHandlerFactoryInterface;
use App\Domains\Media\Registries\ChannelMediaLinkPolicyRegistry;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\InstalledConnectors;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use App\Infrastructure\Flow\ContainerActionHandlerFactory;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use LogicException;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoMediaLinkPolicy;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\Support\FakeVendor;
use Tests\TestCase;

/**
 * Runs the real boot sequence on a fresh application: the booting callback of the Solution provider hands a connector
 * its registrar, and the registries of Channels and Media then serve what the connector registered.
 */
final class ConnectorChannelBootTest extends TestCase
{
    private FakeVendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = new FakeVendor();
    }

    protected function tearDown(): void
    {
        $this->vendor->cleanup();

        parent::tearDown();
    }

    public function test_the_registries_serve_the_channel_a_connector_registered_at_boot(): void
    {
        $app = $this->freshApplication(FakeVendor::connectorComposer());
        $app->boot();

        $registry = $app->make(ChannelRegistryInterface::class);

        $this->assertInstanceOf(EchoSender::class, $registry->sender('echo'));
        $this->assertInstanceOf(EchoInboundAdapter::class, $registry->adapter('echo'));
        $this->assertNull($registry->webhookRegistrar('echo'));
        $this->assertNotSame($registry->sender('echo'), $registry->sender('echo'));
        $this->assertSame('echo', $app->make(ConnectorChannels::class)->ownerOf('echo'));
        $this->assertInstanceOf(EchoMediaLinkPolicy::class, $app->make(ChannelMediaLinkPolicyRegistry::class)->forChannelType('echo'));
    }

    public function test_the_media_registry_is_rebuilt_per_scope_with_fresh_services(): void
    {
        $app = $this->freshApplication(FakeVendor::connectorComposer());
        $app->boot();

        $first = $app->make(ChannelMediaLinkPolicyRegistry::class);
        $this->assertSame($first, $app->make(ChannelMediaLinkPolicyRegistry::class));

        $app->forgetScopedInstances();

        $second = $app->make(ChannelMediaLinkPolicyRegistry::class);

        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->forChannelType('echo'), $second->forChannelType('echo'));
    }

    public function test_connector_channels_are_frozen_after_boot_unless_the_config_flag_leaves_them_open(): void
    {
        $app = $this->freshApplication(FakeVendor::connectorComposer(), freeze: true);
        $app->boot();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Connector channels are registered at boot only.');

        $app->make(ConnectorChannels::class)->add(new ConnectorChannel(type: 'late', sender: EchoSender::class, inbound: EchoInboundAdapter::class), 'late');
    }

    public function test_a_channel_the_manifest_does_not_declare_stops_the_boot(): void
    {
        $app = $this->freshApplication(FakeVendor::connectorComposer(['channels' => ['other']]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Connector "echo" (acme/fapost-connector-echo) registers channel type "echo", which extra.fapost.channels does not declare.');

        $app->boot();
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private function freshApplication(array $composer, bool $freeze = false): Application
    {
        $this->vendor->package($composer)->write();

        $cache      = new SolutionCache(dirname($this->vendor->path) . '/fapost-solutions.php');
        $discoverer = new SolutionDiscoverer(
            new InstalledPackagesReader($this->vendor->path),
            new SolutionManifestValidator(new ManifestParser()),
        );

        $app = new Application(base_path());
        $app->instance('config', new Repository(['solutions' => ['freeze_console_registry' => $freeze]]));
        $app->instance(InstalledSolutions::class, new InstalledSolutions($cache, $discoverer));
        $app->instance(InstalledConnectors::class, new InstalledConnectors($cache, $discoverer));
        $app->singleton(ActionHandlerFactoryInterface::class, ContainerActionHandlerFactory::class);
        $app->singleton(ActionHandlerRegistry::class);
        $app->singleton(ConnectorChannels::class);
        $app->singleton(
            ChannelRegistryInterface::class,
            static fn (Application $app): ChannelRegistry => new ChannelRegistry([], $app, $app->make(ConnectorChannels::class)),
        );
        $app->scoped(
            ChannelMediaLinkPolicyRegistry::class,
            static fn (Application $app): ChannelMediaLinkPolicyRegistry => new ChannelMediaLinkPolicyRegistry($app->tagged('media.channel.link')),
        );
        $app->register(SolutionServiceProvider::class);

        return $app;
    }
}
