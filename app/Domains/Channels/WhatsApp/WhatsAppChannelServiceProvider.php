<?php

declare(strict_types=1);

namespace App\Domains\Channels\WhatsApp;

use App\Domains\Channels\ChannelIntegrationDefinition;
use Illuminate\Support\ServiceProvider;

final class WhatsAppChannelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WhatsAppAdapter::class);

        $this->app->singleton(
            'channels.integration.whatsapp',
            static fn (): ChannelIntegrationDefinition => new ChannelIntegrationDefinition(
                channelType: 'whatsapp',
                adapterClass: WhatsAppAdapter::class,
            )
        );
    }

    public function boot(): void
    {
        $this->app->tag(['channels.integration.whatsapp'], 'channels.integration');
    }
}
