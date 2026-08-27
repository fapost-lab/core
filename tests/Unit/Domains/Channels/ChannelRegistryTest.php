<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\ChannelIntegrationDefinition;
use App\Domains\Channels\ChannelRegistry;
use App\Domains\Channels\Telegram\TelegramAdapter;
use App\Domains\Channels\Telegram\TelegramSender;
use App\Domains\Channels\Telegram\TelegramWebhookRegistrar;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Container\Container;
use Mockery;
use Mockery\MockInterface;
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
}
