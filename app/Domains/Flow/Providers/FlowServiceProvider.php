<?php

declare(strict_types=1);

namespace App\Domains\Flow\Providers;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\HttpClientInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\LanguageResolverInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\MutableDataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\TenantTranslationServiceInterface;
use App\Domains\Flow\Handlers\ConditionNodeHandler;
use App\Domains\Flow\Handlers\DelayNodeHandler;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Flow\Handlers\SetAttributeNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateResolver;
use App\Domains\Flow\Handlers\WebhookNodeHandler;
use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use App\Domains\Flow\Logging\FlowLogPartitionManager;
use App\Domains\Flow\Logging\FlowLogWriter;
use App\Domains\Flow\Orchestration\FlowOrchestrator;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Repositories\FlowDefinitionRepository;
use App\Domains\Flow\Repositories\FlowDraftRepository;
use App\Domains\Flow\Repositories\FlowSessionRepository;
use App\Domains\Flow\Repositories\FlowTriggerRepository;
use App\Domains\Flow\Repositories\TenantEventRepository;
use App\Domains\Flow\Repositories\TenantTranslationRepository;
use App\Domains\Flow\Services\FallbackMessageService;
use App\Domains\Flow\Services\FlowEngine;
use App\Domains\Flow\Services\FlowGraphResolver;
use App\Domains\Flow\Services\FlowInlineKeyboardEditor;
use App\Domains\Flow\Services\FlowMessageSender;
use App\Domains\Flow\Services\FlowSessionPersister;
use App\Domains\Flow\Services\LanguageResolver;
use App\Domains\Flow\Services\PersistentButtonRegistry;
use App\Domains\Flow\Services\Resolvers\ApiTriggerResolver;
use App\Domains\Flow\Services\Resolvers\MessageTriggerResolver;
use App\Domains\Flow\Services\Resolvers\ScheduleTriggerResolver;
use App\Domains\Flow\Services\Resolvers\TriggerResolver;
use App\Domains\Flow\Services\Resolvers\WebhookTriggerResolver;
use App\Domains\Flow\Services\TenantTranslationService;
use App\Domains\Flow\State\Resolvers\ModuleResolutionContext;
use App\Domains\Flow\State\Resolvers\ModuleStateResolver;
use App\Domains\Flow\State\Resolvers\NamespaceResolverRegistry;
use App\Domains\Flow\State\Resolvers\RagStateResolver;
use App\Domains\Flow\State\Resolvers\SessionStateResolver;
use App\Domains\Flow\State\StateNamespace;
use App\Domains\Flow\Support\LaravelHttpClient;
use App\Domains\Flow\Support\ModuleDataAccessorRegistry;
use App\Domains\Flow\Validation\FlowDefinitionValidator;
use App\Domains\Flow\Validation\FlowTriggerConfigValidator;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Infrastructure\Flow\CachedContentTranslator;
use App\Infrastructure\Flow\FlowExecutionGuard;
use FAPost\Foundation\Flow\Contracts\TriggerResolverInterface;
use FAPost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class FlowServiceProvider extends ServiceProvider
{
    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function boot(): void
    {
        $registry  = $this->app->make(NodeHandlerRegistryInterface::class);
        $templates = $this->app->make(TemplateResolver::class);

        $registry->register(
            new SendMessageNodeHandler(
                $this->app->make(MessageSenderInterface::class),
                $this->app->make(ContentTranslatorInterface::class),
                $templates,
                $this->app->make(InlineKeyboardEditorInterface::class),
                $this->app->make(PersistentButtonRegistryInterface::class),
            )
        );
        $registry->register(
            new InputNodeHandler(
                $this->app->make(MediaIngestorInterface::class),
                $this->app->make(MediaServiceInterface::class),
            )
        );
        $registry->register(new ConditionNodeHandler($this->app->make(DataAccessorRegistryInterface::class)));
        $registry->register(new DelayNodeHandler());
        $registry->register(new SetAttributeNodeHandler($templates));
        $registry->register(new WebhookNodeHandler($this->app->make(HttpClientInterface::class)));

        $this->app->booted(function (): void {
            if ( ! $this->app->environment('testing')) {
                $this->app->make(NodeHandlerRegistryInterface::class)->freeze();
            }

            $this->app->make(NamespaceResolverRegistry::class)->freeze();
        });
    }

    public function register(): void
    {
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
                    $app->make(DataAccessorRegistryInterface::class),
                    $app->make(ModuleResolutionContext::class),
                )
            );

            return $registry;
        });

        $this->app->singleton(NodeHandlerRegistry::class);
        $this->app->singleton(
            NodeHandlerRegistryInterface::class,
            fn ($app): NodeHandlerRegistry => $app->make(NodeHandlerRegistry::class)
        );
        $this->app->singleton(FlowDefinitionValidator::class);
        $this->app->singleton(ModuleDataAccessorRegistry::class);
        $this->app->singleton(
            DataAccessorRegistryInterface::class,
            fn ($app): ModuleDataAccessorRegistry => $app->make(ModuleDataAccessorRegistry::class)
        );
        $this->app->singleton(
            MutableDataAccessorRegistryInterface::class,
            fn ($app): ModuleDataAccessorRegistry => $app->make(ModuleDataAccessorRegistry::class)
        );
        $this->app->singleton(HttpClientInterface::class, LaravelHttpClient::class);
        $this->app->singleton(
            MessageSenderInterface::class,
            fn ($app): FlowMessageSender => new FlowMessageSender(
                $app->make(OutboundMessageSenderInterface::class),
                $app->make(MediaServiceInterface::class),
                $app->make(\App\Domains\Media\Contracts\MediaDispatcherInterface::class),
            )
        );
        $this->app->bind(LanguageResolverInterface::class, LanguageResolver::class);
        $this->app->bind(ContentTranslatorInterface::class, CachedContentTranslator::class);
        $this->app->bind(TenantTranslationRepositoryInterface::class, TenantTranslationRepository::class);
        $this->app->bind(TenantEventRepositoryInterface::class, TenantEventRepository::class);
        $this->app->bind(TenantTranslationServiceInterface::class, TenantTranslationService::class);
        $this->app->singleton(TemplateResolver::class);

        $this->app->scoped(FlowEngineInterface::class, FlowEngine::class);
        $this->app->scoped(FlowGraphResolver::class);
        $this->app->scoped(FlowSessionPersister::class);
        $this->app->scoped(FlowLogWriter::class);
        $this->app->scoped(FlowLogPartitionManager::class);
        $this->app->scoped(FlowLogPartitionManagerInterface::class, FlowLogPartitionManager::class);
        $this->app->scoped(FlowSessionRepository::class);
        $this->app->scoped(FlowSessionRepositoryInterface::class, FlowSessionRepository::class);
        $this->app->scoped(FlowDefinitionRepository::class);
        $this->app->scoped(FlowDefinitionRepositoryInterface::class, FlowDefinitionRepository::class);
        $this->app->scoped(FlowDraftRepository::class);
        $this->app->scoped(FlowDraftRepositoryInterface::class, FlowDraftRepository::class);
        $this->app->scoped(FlowTriggerRepository::class);
        $this->app->scoped(FlowTriggerRepositoryInterface::class, FlowTriggerRepository::class);
        $this->app->scoped(FlowTriggerConfigValidatorInterface::class, FlowTriggerConfigValidator::class);
        $this->app->scoped(MessageTriggerResolver::class);
        $this->app->scoped(ScheduleTriggerResolver::class);
        $this->app->scoped(WebhookTriggerResolver::class);
        $this->app->scoped(ApiTriggerResolver::class);
        $this->app->scoped(TriggerResolver::class, fn ($app): TriggerResolver => new TriggerResolver(
            messageResolver: $app->make(MessageTriggerResolver::class),
            scheduleResolver: $app->make(ScheduleTriggerResolver::class),
            webhookResolver: $app->make(WebhookTriggerResolver::class),
            apiResolver: $app->make(ApiTriggerResolver::class),
        ));
        $this->app->scoped(TriggerResolverInterface::class, TriggerResolver::class);
        $this->app->bind(
            FallbackMessageServiceInterface::class,
            fn ($app): FallbackMessageService => new FallbackMessageService(
                $app->make(OutboundMessageSenderInterface::class),
            ),
        );
        $this->app->bind(
            InlineKeyboardEditorInterface::class,
            fn ($app): FlowInlineKeyboardEditor => new FlowInlineKeyboardEditor(
                $app->make(OutboundMessageSenderInterface::class),
            ),
        );
        $this->app->bind(PersistentButtonRegistryInterface::class, PersistentButtonRegistry::class);
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
}
