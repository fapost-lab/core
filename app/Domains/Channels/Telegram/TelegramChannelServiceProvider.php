<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Media\TelegramMediaDownloader;
use App\Domains\Channels\Telegram\Media\TelegramMediaLimits;
use App\Domains\Channels\Telegram\Media\TelegramMediaUploader;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;
use Fapost\Foundation\Lifecycle\AbstractConnectorServiceProvider;
use Fapost\Foundation\Messaging\ButtonLimits;
use Fapost\Foundation\Messaging\ChannelCapabilities;
use Fapost\Foundation\Messaging\ChannelCapability;

/**
 * Boots the Telegram channel module, built in to Core.
 *
 * Registers its channel through the same contract a connector package uses; the registrar differs, see
 * {@see \App\Domains\Channels\BuiltInChannelRegistrar}.
 */
final class TelegramChannelServiceProvider extends AbstractConnectorServiceProvider
{
    /**
     * Register Telegram-specific sender, webhook, adapter, and delivery services.
     */
    protected function registerBindings(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/telegram.php', 'telegram');

        $this->app->bind(TelegramBotApiClientFactory::class, fn ($app): TelegramBotApiClientFactory => new TelegramBotApiClientFactory(
            (string) $app->make('config')->get('telegram.api_base_url', 'https://api.telegram.org'),
        ));
        $this->app->bind(
            TelegramDeliveryResolver::class,
            fn ($app): TelegramDeliveryResolver => new TelegramDeliveryResolver(
                $app->tagged('channels.telegram_delivery')
            )
        );
        $this->app->bind(TelegramTextDeliveryAction::class);
        $this->app->bind(TelegramPhotoDeliveryAction::class);
        $this->app->bind(TelegramDocumentDeliveryAction::class);
        $this->app->bind(TelegramVideoDeliveryAction::class);
        $this->app->bind(TelegramVoiceDeliveryAction::class);
        $this->app->bind(TelegramRemoveKeyboardDeliveryAction::class);
        $this->app->bind(TelegramInboundNormalizer::class);
        $this->app->bind(TelegramSender::class);
        $this->app->bind(TelegramWebhookRegistrar::class);
        $this->app->bind(TelegramAdapter::class);
        $this->app->bind(TelegramMediaUploader::class);
        $this->app->bind(TelegramMediaDownloader::class);
        $this->app->bind(TelegramChannelProfile::class);
        $this->app->bind(TelegramMediaLimits::class);
    }

    /**
     * {@inheritDoc}
     */
    protected function registerChannels(ConnectorRegistrarInterface $registrar): void
    {
        $registrar->registerChannel(new ConnectorChannel(
            type: 'telegram',
            sender: TelegramSender::class,
            inbound: TelegramAdapter::class,
            webhookRegistrar: TelegramWebhookRegistrar::class,
            mediaUploader: TelegramMediaUploader::class,
            mediaDownloader: TelegramMediaDownloader::class,
            // The Bot API documents no keyboard counts and no label length, only callback_data of 1-64 bytes (already the
            // contract's payload cap). 8 buttons per row and 100 per keyboard are the widely observed inline keyboard
            // limits; the label length stays undeclared because clients truncate long labels.
            capabilities: new ChannelCapabilities([
                ChannelCapability::CallbackButtons,
                ChannelCapability::ReplyButtons,
                ChannelCapability::ShareContact,
                ChannelCapability::ShareLocation,
                ChannelCapability::RemoveButtons,
                ChannelCapability::PersistentButtons,
            ], new ButtonLimits(maxButtons: 100, maxButtonsPerRow: 8)),
            profile: TelegramChannelProfile::class,
            mediaLimits: TelegramMediaLimits::class,
        ));
    }

    /**
     * Publish the Telegram delivery actions. The media classes are tagged by the registration of the channel.
     */
    protected function bootConnector(): void
    {
        $this->app->tag([
            TelegramTextDeliveryAction::class,
            TelegramPhotoDeliveryAction::class,
            TelegramDocumentDeliveryAction::class,
            TelegramVideoDeliveryAction::class,
            TelegramVoiceDeliveryAction::class,
            TelegramRemoveKeyboardDeliveryAction::class,
        ], 'channels.telegram_delivery');
    }
}
