<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Assistant\Contracts\AssistantFlowConfigRepositoryInterface;
use App\Domains\Assistant\Contracts\AssistantRepositoryInterface;
use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Policies\AssistantPolicy;
use App\Domains\Assistant\Repositories\AssistantFlowConfigRepository;
use App\Domains\Assistant\Repositories\AssistantRepository;
use App\Domains\Assistant\Services\AssistantService;
use App\Domains\Assistant\Services\CurrentAssistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Contracts\ChannelWebhookStatusRecorderInterface;
use App\Domains\Channels\Services\ChannelService;
use App\Domains\Channels\Services\ChannelWebhookRegistry;
use App\Domains\Channels\Services\ChannelWebhookStatusRecorder;
use App\Domains\Channels\Services\ChannelWebhookSyncOutcome;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Closure;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Assistant bounded context provider.
 *
 * Registers assistant/channel services and policies, plus model observers.
 */
final class AssistantServiceProvider extends ServiceProvider
{
    /**
     * Register assistant domain service bindings.
     */
    public function register(): void
    {
        $this->app->singleton(ChannelWebhookRegistryInterface::class, ChannelWebhookRegistry::class);
        $this->app->bind(AssistantFlowConfigRepositoryInterface::class, AssistantFlowConfigRepository::class);
        $this->app->bind(AssistantRepositoryInterface::class, AssistantRepository::class);
        $this->app->bind(AssistantServiceInterface::class, AssistantService::class);
        $this->app->bind(ChannelServiceInterface::class, ChannelService::class);
        $this->app->bind(ChannelWebhookStatusRecorderInterface::class, ChannelWebhookStatusRecorder::class);
        $this->app->scoped(ChannelWebhookSyncOutcome::class);
        $this->app->scoped(CurrentAssistant::class, CurrentAssistant::class);
        $this->app->scoped(
            CurrentAssistantInterface::class,
            fn ($app): CurrentAssistant => $app->make(CurrentAssistant::class)
        );

        $this->app->afterResolving(TenantSwitcher::class, function (TenantSwitcher $switcher, $app): void {
            // The inner tenant never sees the outer assistant, and the outer caller gets it back: a synchronous job
            // inside an HTTP request must not leave the request without its assistant. At the top level of a worker
            // there is nothing to put back, so the exit resets, as it always did.
            $switcher->registerContextHook(function () use ($app): Closure {
                $current  = $app->make(CurrentAssistantInterface::class);
                $previous = $current->isResolved() ? $current->get() : null;

                $current->reset();

                return static function () use ($current, $previous): void {
                    null === $previous ? $current->reset() : $current->set($previous);
                };
            });
        });
    }

    /**
     * Register assistant authorization policies and observers.
     */
    public function boot(): void
    {
        Gate::policy(Assistant::class, AssistantPolicy::class);

        // Registered the way a Solution registers its keys: from boot(), before the registry is frozen.
        $this->app->make(LimitRegistryInterface::class)->register(new LimitDefinition(
            key: Assistant::LIMIT_KEY,
            label: 'Assistants',
            unit: 'assistants',
            kind: LimitKind::Records,
            description: 'How many assistants a tenant can have at a time.',
        ));
    }
}
