<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Providers;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Messaging\MessageSender;
use FAPost\Foundation\Messaging\MessageSenderInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the generic messaging engine and hooks channel lifecycle events.
 */
final class MessageSenderServiceProvider extends ServiceProvider
{
    /**
     * Register the application-wide outbound message sender binding.
     */
    public function register(): void
    {
        $this->app->singleton(
            MessageSenderInterface::class,
            fn ($app): MessageSender => new MessageSender(
                channelRegistry: $app->make(ChannelRegistryInterface::class),
                redis: $app->make('redis'),
                rateLimitPerMinute: (int) config('messaging.rate_limit_per_minute', 30),
            )
        );
    }

}
