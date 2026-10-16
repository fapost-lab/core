<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Providers;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Messaging\MessageSender;
use App\Domains\Messaging\OutboundVolumeGate;
use App\Domains\Messaging\TenantMessagingRateLimit;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
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
        // across jobs on a long-lived worker. The same goes for the rate limit, which
        // memoizes the tenant's setting for the life of the scope.
        $this->app->scoped(
            TenantMessagingRateLimit::class,
            fn ($app): TenantMessagingRateLimit => new TenantMessagingRateLimit(
                $app->make(TenantContextInterface::class),
                (int)config('messaging.rate_limit_per_minute', 30),
            )
        );
        $this->app->scoped(
            MessageSenderInterface::class,
            fn ($app): MessageSender => new MessageSender(
                channelRegistry: $app->make(ChannelRegistryInterface::class),
                redis: $app->make('redis'),
                rateLimit: $app->make(TenantMessagingRateLimit::class),
                conversationLogger: $app->make(ConversationLoggerInterface::class),
                captureFactory: $app->make(ConversationCaptureFactory::class),
                volumeGate: $app->make(OutboundVolumeGate::class),
            )
        );
    }

    /**
     * Register the outbound volume limit key, the way a Solution registers its own.
     */
    public function boot(): void
    {
        $this->app->make(LimitRegistryInterface::class)->register(new LimitDefinition(
            key: OutboundVolumeGate::LIMIT_KEY,
            label: 'Outbound messages',
            unit: 'messages',
            kind: LimitKind::PerPeriod,
            description: 'Messages delivered to contacts in the period: flow replies, broadcasts, notifications and staff replies. A message over the limit is not sent.',
        ));
    }
}
