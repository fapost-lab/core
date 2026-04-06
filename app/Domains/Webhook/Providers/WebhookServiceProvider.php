<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Providers;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Adapters\TelegramChannelAdapter;
use App\Domains\Webhook\Adapters\WhatsAppChannelAdapter;
use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use App\Domains\Webhook\Services\WebhookRegistryResolver;
use Illuminate\Support\ServiceProvider;

final class WebhookServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, class-string<\App\Domains\Webhook\Contracts\ChannelAdapterInterface>>
     */
    public const array ADAPTER_MAP = [
        PlatformEnum::Telegram->value => TelegramChannelAdapter::class,
        PlatformEnum::WhatsApp->value => WhatsAppChannelAdapter::class,
    ];

    public function register(): void
    {
        $this->app->singleton(
            ChannelAdapterResolver::class,
            fn ($app): ChannelAdapterResolver => new ChannelAdapterResolver(
                container: $app,
                adapterMap: self::ADAPTER_MAP,
            )
        );

        $this->app->singleton(WebhookRegistryResolverInterface::class, WebhookRegistryResolver::class);
    }

    public function boot(): void
    {
    }
}
