<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Providers;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Messaging\MessageSender;
use Fapost\Foundation\Messaging\MessageSenderInterface;
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
        // Scoped (not singleton): the sender now depends on the scoped, tenant-aware
        // conversation logger, so it must be rebuilt per scope rather than shared
        // across jobs on a long-lived worker.
        $this->app->scoped(
            MessageSenderInterface::class,
            fn ($app): MessageSender => new MessageSender(
                channelRegistry: $app->make(ChannelRegistryInterface::class),
                redis: $app->make('redis'),
                rateLimitPerMinute: (int)config('messaging.rate_limit_per_minute', 30),
                conversationLogger: $app->make(ConversationLoggerInterface::class),
                captureFactory: $app->make(ConversationCaptureFactory::class),
            )
        );
    }

}
