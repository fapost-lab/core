<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Providers;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\Enums\IngressDriver;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use App\Domains\Webhook\Services\IngressSpecResolver;
use App\Domains\Webhook\Services\WebhookRegistryResolver;
use App\Domains\Webhook\Services\WebhookUrlGenerator;
use Illuminate\Support\ServiceProvider;

final class WebhookServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            WebhookUrlGenerator::class,
            fn ($app): WebhookUrlGenerator => new WebhookUrlGenerator(
                baseUrl: $app['config']->get('webhook.base_url'),
                gatewayUrl: $app['config']->get('webhook.ingress.gateway_url'),
                driver: IngressDriver::fromConfig($app['config']->get('webhook.ingress.driver')),
                // Derived from the registered adapters, never configured: see IngressSpecResolver.
                gatewayPlatforms: $app->make(IngressSpecResolver::class)->declarativePlatforms(),
            )
        );

        $this->app->singleton(
            ChannelAdapterResolver::class,
            fn ($app): ChannelAdapterResolver => new ChannelAdapterResolver(
                channelRegistry: $app->make(ChannelRegistryInterface::class),
            )
        );

        $this->app->singleton(WebhookRegistryResolverInterface::class, WebhookRegistryResolver::class);
    }
}
