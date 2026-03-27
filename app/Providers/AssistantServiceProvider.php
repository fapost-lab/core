<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\Contracts\ChannelServiceInterface;
use App\Domains\Assistant\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Models\Channel;
use App\Domains\Assistant\Observers\ChannelObserver;
use App\Domains\Assistant\Policies\AssistantPolicy;
use App\Domains\Assistant\Policies\ChannelPolicy;
use App\Domains\Assistant\Services\AssistantService;
use App\Domains\Assistant\Services\ChannelService;
use App\Domains\Assistant\Services\ChannelWebhookRegistry;
use App\Domains\Assistant\Services\CurrentAssistant;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelWebhookRegistryInterface::class, ChannelWebhookRegistry::class);
        $this->app->bind(AssistantServiceInterface::class, AssistantService::class);
        $this->app->bind(ChannelServiceInterface::class, ChannelService::class);
        $this->app->scoped(CurrentAssistant::class, CurrentAssistant::class);
        $this->app->scoped(CurrentAssistantInterface::class, fn ($app): CurrentAssistant => $app->make(CurrentAssistant::class));
    }

    public function boot(): void
    {
        Gate::policy(Assistant::class, AssistantPolicy::class);
        Gate::policy(Channel::class, ChannelPolicy::class);
        Channel::observe(ChannelObserver::class);
    }
}
