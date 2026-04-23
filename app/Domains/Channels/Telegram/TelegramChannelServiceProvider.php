<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\ChannelIntegrationDefinition;
use App\Domains\Channels\Telegram\Contracts\TelegramBotIdentityStoreInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the Telegram channel integration module.
 */
final class TelegramChannelServiceProvider extends ServiceProvider
{
    /**
     * Register Telegram-specific sender, webhook, adapter, and delivery services.
     */
    public function register(): void
    {
        $this->app->bind(TelegramBotApiClientFactory::class);
        $this->app->bind(TelegramBotIdentityStoreInterface::class, TelegramBotIdentityStore::class);
        $this->app->bind(TelegramDeliveryResolver::class, fn ($app): TelegramDeliveryResolver => new TelegramDeliveryResolver(
            $app->tagged('channels.telegram_delivery')
        ));
        $this->app->bind(TelegramTextDeliveryAction::class);
        $this->app->bind(TelegramPhotoDeliveryAction::class);
        $this->app->bind(TelegramDocumentDeliveryAction::class);
        $this->app->bind(TelegramVideoDeliveryAction::class);
        $this->app->bind(TelegramVoiceDeliveryAction::class);
        $this->app->bind(TelegramSignatureVerifier::class);
        $this->app->bind(TelegramInboundNormalizer::class);
        $this->app->bind(TelegramSender::class);
        $this->app->bind(TelegramWebhookRegistrar::class);
        $this->app->bind(TelegramAdapter::class);

        $this->app->singleton('channels.integration.telegram', static fn (): ChannelIntegrationDefinition => new ChannelIntegrationDefinition(
            channelType: 'telegram',
            senderClass: TelegramSender::class,
            webhookRegistrarClass: TelegramWebhookRegistrar::class,
            adapterClass: TelegramAdapter::class,
        ));
    }

    /**
     * Publish Telegram service tags and the integration definition.
     */
    public function boot(): void
    {
        $this->app->tag([
            TelegramTextDeliveryAction::class,
            TelegramPhotoDeliveryAction::class,
            TelegramDocumentDeliveryAction::class,
            TelegramVideoDeliveryAction::class,
            TelegramVoiceDeliveryAction::class,
        ], 'channels.telegram_delivery');
        $this->app->tag(['channels.integration.telegram'], 'channels.integration');
    }
}
