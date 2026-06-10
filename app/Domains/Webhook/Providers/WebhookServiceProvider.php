<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Providers;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use App\Domains\Webhook\Services\WebhookRegistryResolver;
use App\Domains\Webhook\Services\WebhookUrlGenerator;
use Illuminate\Support\ServiceProvider;

final class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->when(WebhookUrlGenerator::class)
            ->needs('$baseUrl')
            ->giveConfig('webhook.base_url');

        $this->app->singleton(
            ChannelAdapterResolver::class,
            fn ($app): ChannelAdapterResolver => new ChannelAdapterResolver(
                channelRegistry: $app->make(ChannelRegistryInterface::class),
            )
        );

        $this->app->singleton(WebhookRegistryResolverInterface::class, WebhookRegistryResolver::class);
    }
}
