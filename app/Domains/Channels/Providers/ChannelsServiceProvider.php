<?php

declare(strict_types=1);

namespace App\Domains\Channels\Providers;

use App\Domains\Channels\ChannelRegistry;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Policies\ChannelPolicy;
use App\Domains\Channels\Telegram\TelegramChannelServiceProvider;
use App\Domains\Channels\WhatsApp\WhatsAppChannelServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the shared channel registry and all shipped channel modules.
 */
final class ChannelsServiceProvider extends ServiceProvider
{
    /**
     * Register channel modules and expose the channel registry binding.
     */
    public function register(): void
    {
        $this->app->register(TelegramChannelServiceProvider::class);
        $this->app->register(WhatsAppChannelServiceProvider::class);

        $this->app->singleton(
            ChannelRegistryInterface::class,
            fn ($app): ChannelRegistry => new ChannelRegistry(
                definitions: $app->tagged('channels.integration'),
                container: $app,
            )
        );
    }

    public function boot(): void
    {
        Gate::policy(Channel::class, ChannelPolicy::class);
    }
}
