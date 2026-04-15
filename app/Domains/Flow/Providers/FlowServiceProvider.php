<?php

declare(strict_types=1);

namespace App\Domains\Flow\Providers;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Orchestration\FlowOrchestrator;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Repositories\FlowDefinitionRepository;
use App\Domains\Flow\Repositories\FlowSessionRepository;
use App\Domains\Flow\Services\FlowEngine;
use App\Domains\Flow\Services\FlowGraphResolver;
use App\Domains\Flow\Services\FlowLogWriter;
use App\Domains\Flow\Services\FlowSessionPersister;
use App\Domains\Flow\State\Resolvers\ModuleResolutionContext;
use App\Domains\Flow\State\Resolvers\ModuleStateResolver;
use App\Domains\Flow\State\Resolvers\NamespaceResolverRegistry;
use App\Domains\Flow\State\Resolvers\RagStateResolver;
use App\Domains\Flow\State\Resolvers\SessionStateResolver;
use App\Domains\Flow\State\StateNamespace;
use App\Domains\Flow\Validation\FlowDefinitionValidator;
use App\Domains\Shared\Registries\ModuleNamespaceRegistry;
use App\Infrastructure\Flow\FlowExecutionGuard;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class FlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleNamespaceRegistry::class);
        // NOTE: ModuleResolutionContext is scoped (per-request), but NamespaceResolverRegistry
        // is a singleton. This is safe because flow execution runs in queue workers (FPM),
        // never in Octane ingress. If that changes — audit this binding.
        $this->app->scoped(ModuleResolutionContext::class);
        $this->app->singleton(NamespaceResolverRegistry::class, function ($app): NamespaceResolverRegistry {
            $registry = new NamespaceResolverRegistry();

            $registry->register(StateNamespace::System, new SessionStateResolver(StateNamespace::System));
            $registry->register(StateNamespace::Flow, new SessionStateResolver(StateNamespace::Flow));
            $registry->register(StateNamespace::Rag, new RagStateResolver());
            $registry->register(
                StateNamespace::Module,
                new ModuleStateResolver(
                    $app->make(ModuleNamespaceRegistry::class),
                    $app->make(ModuleResolutionContext::class),
                )
            );

            return $registry;
        });

        $this->app->singleton(NodeHandlerRegistry::class);
        $this->app->singleton(NodeHandlerRegistryInterface::class, fn ($app): NodeHandlerRegistry => $app->make(NodeHandlerRegistry::class));
        $this->app->singleton(FlowDefinitionValidator::class);

        $this->app->scoped(FlowEngineInterface::class, FlowEngine::class);
        $this->app->scoped(FlowGraphResolver::class);
        $this->app->scoped(FlowSessionPersister::class);
        $this->app->scoped(FlowLogWriter::class);
        $this->app->scoped(FlowSessionRepository::class);
        $this->app->scoped(FlowSessionRepositoryInterface::class, FlowSessionRepository::class);
        $this->app->scoped(FlowDefinitionRepository::class);
        $this->app->scoped(FlowDefinitionRepositoryInterface::class, FlowDefinitionRepository::class);
        $this->app->scoped(FlowOrchestrator::class);
        $this->app->scoped(FlowOrchestratorInterface::class, FlowOrchestrator::class);
        $this->app->singleton(FlowExecutionGuardInterface::class, function (): FlowExecutionGuard {
            $store = Cache::store('redis')->getStore();

            if ( ! $store instanceof LockProvider) {
                throw new LogicException('Configured redis cache store does not support distributed locks.');
            }

            return new FlowExecutionGuard(store: $store, ttl: 30);
        });
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            if ( ! $this->app->environment('testing')) {
                $this->app->make(NodeHandlerRegistryInterface::class)->freeze();
            }

            $this->app->make(NamespaceResolverRegistry::class)->freeze();
            $this->app->make(ModuleNamespaceRegistry::class)->freeze();
        });
    }
}
