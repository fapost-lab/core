<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\ChannelIntegrationDefinition;
use App\Domains\Channels\ChannelRegistry;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Telegram\TelegramAdapter;
use App\Domains\Channels\Telegram\TelegramSender;
use App\Domains\Channels\Telegram\TelegramWebhookRegistrar;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Container\Container;
use Mockery;
use Mockery\MockInterface;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\TestCase;

final class ChannelRegistryTest extends TestCase
{
    public function test_sender_returns_container_resolved_sender_for_enabled_channel(): void
    {
        /** @var Container&MockInterface $container */
        $container = Mockery::mock(Container::class);
        $sender    = $this->mock(ProviderSenderInterface::class);

        $container
            ->shouldReceive('make')
            ->once()
            ->with(TelegramSender::class)
            ->andReturn($sender);

        $registry = new ChannelRegistry([
            new ChannelIntegrationDefinition(
                channelType: 'telegram',
                senderClass: TelegramSender::class,
            ),
        ], $container);

        $this->assertSame($sender, $registry->sender('telegram'));
    }

    public function test_registry_ignores_unpublished_channel_definitions(): void
    {
        /** @var Container&MockInterface $container */
        $container = Mockery::mock(Container::class);
        $container->shouldNotReceive('make');

        $registry = new ChannelRegistry([
            new ChannelIntegrationDefinition(
                channelType: 'viber',
                senderClass: TelegramSender::class,
                webhookRegistrarClass: TelegramWebhookRegistrar::class,
                adapterClass: TelegramAdapter::class,
            ),
        ], $container);

        $this->assertNull($registry->definition('viber'));
        $this->assertNull($registry->sender('viber'));
        $this->assertNull($registry->webhookRegistrar('viber'));
        $this->assertNull($registry->adapter('viber'));
    }

    public function test_a_connector_channel_is_served_through_the_same_calls(): void
    {
        $registry = new ChannelRegistry([], $this->app, $this->connectorChannels());

        $this->assertInstanceOf(EchoSender::class, $registry->sender('echo'));
        $this->assertInstanceOf(EchoInboundAdapter::class, $registry->adapter('echo'));
        $this->assertNull($registry->webhookRegistrar('echo'));
        $this->assertSame('echo', $registry->definition('echo')?->connectorId);
        $this->assertNull($registry->definition('echo')?->webhookRegistrarClass);
    }

    public function test_services_of_a_connector_channel_are_built_per_call(): void
    {
        $registry = new ChannelRegistry([], $this->app, $this->connectorChannels());

        $this->assertNotSame($registry->sender('echo'), $registry->sender('echo'));
        $this->assertNotSame($registry->adapter('echo'), $registry->adapter('echo'));
    }

    public function test_a_channel_registered_after_the_registry_was_built_is_found(): void
    {
        $channels = new ConnectorChannels();
        $registry = new ChannelRegistry([], $this->app, $channels);

        $this->assertNull($registry->sender('echo'));

        $channels->add(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class), 'echo');

        $this->assertInstanceOf(EchoSender::class, $registry->sender('echo'));
    }

    public function test_a_built_in_definition_has_no_connector(): void
    {
        $registry = new ChannelRegistry([
            new ChannelIntegrationDefinition(channelType: 'telegram', senderClass: TelegramSender::class),
        ], $this->app, $this->connectorChannels());

        $this->assertNull($registry->definition('telegram')?->connectorId);
    }

    public function test_it_lists_the_built_in_types_then_the_connectors(): void
    {
        $registry = new ChannelRegistry([
            new ChannelIntegrationDefinition(channelType: 'telegram', senderClass: TelegramSender::class),
        ], $this->app, $this->connectorChannels());

        $this->assertSame(['telegram', 'echo'], $registry->types());
        $this->assertTrue($registry->has('telegram'));
        $this->assertTrue($registry->has('echo'));
        $this->assertFalse($registry->has('viber'));
    }

    public function test_a_built_in_type_is_labelled_by_translation_and_a_connector_type_by_its_name(): void
    {
        $registry = new ChannelRegistry([
            new ChannelIntegrationDefinition(channelType: 'telegram', senderClass: TelegramSender::class),
        ], $this->app, $this->connectorChannels());

        $this->assertSame('Telegram', $registry->label('telegram'));
        $this->assertSame('Echo Connector', $registry->label('echo'));
        $this->assertSame('viber', $registry->label('viber'));
    }

    private function connectorChannels(): ConnectorChannels
    {
        $channels = new ConnectorChannels();
        $channels->add(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class), 'echo', 'Echo Connector');

        return $channels;
    }
}
