<?php

declare(strict_types=1);

namespace App\Domains\Channels\WhatsApp;

use App\Domains\Channels\ChannelIntegrationDefinition;
use App\Domains\Webhook\Adapters\WhatsAppChannelAdapter;
use Illuminate\Support\ServiceProvider;

final class WhatsAppChannelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WhatsAppChannelAdapter::class);

        $this->app->singleton('channels.integration.whatsapp', static fn (): ChannelIntegrationDefinition => new ChannelIntegrationDefinition(
            channelType: 'whatsapp',
            adapterClass: WhatsAppChannelAdapter::class,
        ));
    }

    public function boot(): void
    {
        $this->app->tag(['channels.integration.whatsapp'], 'channels.integration');
    }
}
