<?php

declare(strict_types=1);

namespace App\Domains\Channels\Providers;

use App\Domains\Channels\BuiltInChannelRegistrar;
use App\Domains\Channels\ChannelRegistry;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Policies\ChannelPolicy;
use App\Domains\Channels\Services\ChannelManagedSettings;
use App\Domains\Channels\Services\ChannelProviderWebhookRemover;
use App\Domains\Channels\Telegram\TelegramChannelServiceProvider;
use App\Domains\Tenancy\Contracts\ChannelWebhookRemoverInterface;
use App\Domains\Tenancy\Services\TenantUsageCounters;
use Fapost\Foundation\Channel\Settings\ManagedChannelSettingsInterface;
use Fapost\Foundation\Lifecycle\AbstractConnectorServiceProvider;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the shared channel registry and the channel modules built in to Core.
 *
 * A built-in module is a connector-style provider: it registers its channel through a registrar, here the
 * {@see BuiltInChannelRegistrar}. That happens in a booting callback, which runs after every register() and before
 * the first boot(), and before the one of the Solution provider that registers the connectors' channels and freezes
 * the registry, so the built-in channels come first.
 */
final class ChannelsServiceProvider extends ServiceProvider
{
    /**
     * The channel modules Core ships, the only ones a {@see BuiltInChannelRegistrar} serves.
     *
     * @var list<class-string<AbstractConnectorServiceProvider>>
     */
    private const array BUILT_IN_MODULES = [
        TelegramChannelServiceProvider::class,
    ];

    /**
     * Register channel modules and expose the channel registry binding.
     */
    public function register(): void
    {
        $this->app->bind(ChannelWebhookRemoverInterface::class, ChannelProviderWebhookRemover::class);

        $modules = [];

        foreach (self::BUILT_IN_MODULES as $module) {
            $provider = $this->app->resolveProvider($module);
            $this->app->register($provider);
            $modules[] = $provider;
        }

        $this->app->booting(function () use ($modules): void {
            $registrar = new BuiltInChannelRegistrar($this->app->make(ConnectorChannels::class), $this->app);

            foreach ($modules as $module) {
                $module->registerChannelsWith($registrar);
            }

            // Registration builds a throwaway media service per class, outside any tenant, and its scoped
            // dependencies would otherwise stay in the container for the rest of a PHP-FPM request.
            $this->app->forgetScopedInstances();
        });

        // Depends on the image alone (class names), never on a tenant, so a process may keep it.
        $this->app->singleton(ConnectorChannels::class);

        // Built per resolution and keeping nothing: it reads and writes the current tenant's channel each time.
        $this->app->bind(ManagedChannelSettingsInterface::class, ChannelManagedSettings::class);

        $this->app->singleton(
            ChannelRegistryInterface::class,
            fn ($app): ChannelRegistry => new ChannelRegistry(
                container: $app,
                channels: $app->make(ConnectorChannels::class),
            )
        );
    }

    public function boot(): void
    {
        Gate::policy(Channel::class, ChannelPolicy::class);

        // Registered the way a Solution registers its keys: from boot(), before the registry is frozen.
        $this->app->make(LimitRegistryInterface::class)->register(new LimitDefinition(
            key: Channel::LIMIT_KEY,
            label: 'Channels',
            unit: 'channels',
            kind: LimitKind::Records,
            description: 'How many channels a tenant can have at a time, inactive ones included.',
        ));

        $this->app->make(TenantUsageCounters::class)->register(Channel::LIMIT_KEY, Channel::countForLimit(...));
    }
}
