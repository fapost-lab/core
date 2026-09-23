<?php

declare(strict_types=1);

namespace App\Domains\Flow\Providers;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Call\Transports\HandlerTransport;
use App\Domains\Flow\Call\Transports\HttpTransport;
use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\CommandMatcher;
use App\Domains\Flow\Commands\GlobalCommandExecutor;
use App\Domains\Flow\Commands\GlobalCommandExecutorInterface;
use App\Domains\Flow\Concurrency\LockAcquisitionPolicy;
use App\Domains\Flow\Concurrency\LockHeartbeat;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Concurrency\SessionLockRegistry;
use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use App\Domains\Flow\Contracts\AssistantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\AssistantTranslationServiceInterface;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\DelayResumeSchedulerInterface;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowOrchestratorInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\LanguageResolverInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\MutableDataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\NodeUsageStatisticsInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Contracts\SendMessageTimeoutSchedulerInterface;
use App\Domains\Flow\Contracts\SystemTranslationCatalogInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\TenantTranslationServiceInterface;
use App\Domains\Flow\Contracts\VariableCoercerInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\Events\QueuedFlowTriggerEventPublisher;
use App\Domains\Flow\Expression\Engines\TemplateEngine;
use App\Domains\Flow\Expression\ExpressionEngineRegistry;
use App\Domains\Flow\Handlers\AssignNodeHandler;
use App\Domains\Flow\Handlers\AuthRequestNodeHandler;
use App\Domains\Flow\Handlers\BranchNodeHandler;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Handlers\DelayNodeHandler;
use App\Domains\Flow\Handlers\EmitEventNodeHandler;
use App\Domains\Flow\Handlers\EndNodeHandler;
use App\Domains\Flow\Handlers\InputNodeHandler;
use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\LoopNodeHandler;
use App\Domains\Flow\Handlers\NotifyNodeHandler;
use App\Domains\Flow\Handlers\RagQueryNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Flow\Handlers\SetTagNodeHandler;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Handlers\Support\TemplateResolver;
use App\Domains\Flow\History\DefaultHistoryWriter;
use App\Domains\Flow\History\HistoryWriterFactory;
use App\Domains\Flow\History\NoOpHistoryWriter;
use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use App\Domains\Flow\Logging\FlowLogPartitionManager;
use App\Domains\Flow\Logging\FlowLogWriter;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\DelayedSessionResumer;
use App\Domains\Flow\Orchestration\FlowOrchestrator;
use App\Domains\Flow\Policies\FlowDraftPolicy;
use App\Domains\Flow\Policies\FlowGroupPolicy;
use App\Domains\Flow\Policies\FlowLogPolicy;
use App\Domains\Flow\Policies\FlowSessionPolicy;
use App\Domains\Flow\Rag\RagAdapterRegistry;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Repositories\AssistantTranslationRepository;
use App\Domains\Flow\Repositories\FlowDefinitionRepository;
use App\Domains\Flow\Repositories\FlowDraftRepository;
use App\Domains\Flow\Repositories\FlowSessionRepository;
use App\Domains\Flow\Repositories\FlowTriggerRepository;
use App\Domains\Flow\Repositories\TenantEventRepository;
use App\Domains\Flow\Repositories\TenantTranslationRepository;
use App\Domains\Flow\Routing\DropPolicy;
use App\Domains\Flow\Routing\DropPolicyInterface;
use App\Domains\Flow\Routing\MessageRouter;
use App\Domains\Flow\Routing\SessionStateRouter;
use App\Domains\Flow\Services\AssistantTranslationService;
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
use App\Domains\Flow\State\Variables\CacheBackedVariableSchemaRegistry;
use App\Domains\Flow\State\Variables\VariableCoercer;
use App\Domains\Flow\State\Variables\VariableResolver;
use App\Domains\Flow\Statistics\NodeUsageStatisticsService;
use App\Domains\Flow\Subflow\CallGraphRepository;
use App\Domains\Flow\Subflow\CallGraphValidator;
use App\Domains\Flow\Subflow\DefaultSubflowResumer;
use App\Domains\Flow\Subflow\SubflowResumerInterface;
use App\Domains\Flow\Subflow\SubflowStarterService;
use App\Domains\Flow\Subflow\SubflowTimeoutSweeper;
use App\Domains\Flow\Support\ModuleDataAccessorRegistry;
use App\Domains\Flow\Translations\CoreSystemTranslations;
use App\Domains\Flow\Translations\InMemorySystemTranslationCatalog;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use App\Domains\Flow\Validation\FlowDefinitionValidator;
use App\Domains\Flow\Validation\FlowTriggerConfigValidator;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Messaging\Typing\TypingHeartbeatRegistry;
use App\Domains\Messaging\Typing\TypingIndicatorService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Infrastructure\Flow\CachedContentTranslator;
use App\Infrastructure\Flow\ConnectionAfterCommitDispatcher;
use App\Infrastructure\Flow\ContainerNodeHandlerFactory;
use App\Infrastructure\Flow\FlowExecutionGuard;
use App\Infrastructure\Flow\QueuedDelayResumeScheduler;
use App\Infrastructure\Flow\QueuedSendMessageTimeoutScheduler;
use Fapost\Foundation\Flow\Contracts\TriggerResolverInterface;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class FlowServiceProvider extends ServiceProvider
{
    /**
     * Late runtime hooks only (boot lifecycle law): authorization policies,
     * the idempotent system-translation seed and registry freezing. All Core
     * handler/engine/transport registration is declared in {@see register()}
     * inside the registry singleton factories and runs lazily on first resolve.
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function boot(): void
    {
        Gate::policy(FlowDraft::class, FlowDraftPolicy::class);
        Gate::policy(FlowGroup::class, FlowGroupPolicy::class);
        Gate::policy(FlowSession::class, FlowSessionPolicy::class);
        Gate::policy(FlowLog::class, FlowLogPolicy::class);

        // Seed Core's system translation keys. Features and Solutions register
        // their own keys through the same catalog from their providers.
        // Idempotent so the test bootstrap can boot the provider repeatedly.
        $catalog = $this->app->make(SystemTranslationCatalogInterface::class);
        if ([] === $catalog->entries()) {
            CoreSystemTranslations::seed($catalog);
        }

        $this->app->booted(function (): void {
            if (!$this->app->environment('testing')) {
                $this->app->make(NodeHandlerRegistryInterface::class)->freeze();
                $this->app->make(ExpressionEngineRegistry::class)->freeze();
                $this->app->make(CallTransportRegistry::class)->freeze();
                $this->app->make(ActionHandlerRegistry::class)->freeze();
                $this->app->make(RagAdapterRegistry::class)->freeze();
            }
        });
    }

    public function register(): void
    {
        // Registry contents are declared here (register phase, lazily executed
        // on first resolve) — boot() only freezes them. Solutions/Plugins add
        // their own entries through CoreRegistrar before the freeze. The registry
        // keeps handler classes and builds each handler in the current scope.
        $this->app->singleton(NodeHandlerFactoryInterface::class, ContainerNodeHandlerFactory::class);
        $this->app->singleton(NodeHandlerRegistry::class, function ($app): NodeHandlerRegistry {
            $registry = new NodeHandlerRegistry($app->make(NodeHandlerFactoryInterface::class));
            $this->registerCoreNodeHandlers($registry);

            return $registry;
        });
        $this->app->singleton(
            NodeHandlerRegistryInterface::class,
            fn ($app): NodeHandlerRegistry => $app->make(NodeHandlerRegistry::class)
        );
        $this->app->singleton(FlowDefinitionValidator::class);
        $this->app->singleton(VariableCoercerInterface::class, VariableCoercer::class);
        $this->app->singleton(
            \App\Domains\Flow\Validation\InputValidatorInterface::class,
            \App\Domains\Flow\Validation\InputValidator::class,
        );
        $this->app->bind(
            VariableSchemaRegistryInterface::class,
            fn ($app): CacheBackedVariableSchemaRegistry => new CacheBackedVariableSchemaRegistry(
                tenantContext: $app->make(TenantContextInterface::class),
                cache: $app->make('cache.store'),
            )
        );
        // Scoped: the schema registry belongs to the current tenant.
        $this->app->scoped(VariableResolverInterface::class, fn ($app): VariableResolver => new VariableResolver(
            coercer: $app->make(VariableCoercerInterface::class),
            schemaRegistry: $app->make(VariableSchemaRegistryInterface::class),
        ));
        $this->app->singleton(ModuleDataAccessorRegistry::class);
        $this->app->singleton(
            DataAccessorRegistryInterface::class,
            fn ($app): ModuleDataAccessorRegistry => $app->make(ModuleDataAccessorRegistry::class)
        );
        $this->app->singleton(
            MutableDataAccessorRegistryInterface::class,
            fn ($app): ModuleDataAccessorRegistry => $app->make(ModuleDataAccessorRegistry::class)
        );
        // Scoped (not singleton): it depends on the scoped, tenant-aware
        // conversation logger, so it must rebuild per scope rather than persist across
        // jobs — consistent with the Foundation MessageSender binding.
        $this->app->scoped(
            MessageSenderInterface::class,
            fn ($app): FlowMessageSender => new FlowMessageSender(
                $app->make(OutboundMessageSenderInterface::class),
                $app->make(MediaDispatcherInterface::class),
                $app->make(\App\Domains\Conversation\Contracts\ConversationLoggerInterface::class),
                $app->make(\App\Domains\Conversation\Capture\ConversationCaptureFactory::class),
            )
        );
        $this->app->bind(LanguageResolverInterface::class, LanguageResolver::class);
        $this->app->singleton(
            \App\Domains\Flow\Contracts\FlowAccessPolicyInterface::class,
            \App\Domains\Flow\Services\FlowAccessPolicy::class,
        );
        $this->app->bind(ContentTranslatorInterface::class, CachedContentTranslator::class);
        $this->app->bind(TenantTranslationRepositoryInterface::class, TenantTranslationRepository::class);
        $this->app->bind(AssistantTranslationRepositoryInterface::class, AssistantTranslationRepository::class);
        $this->app->bind(TenantEventRepositoryInterface::class, TenantEventRepository::class);
        $this->app->bind(FlowTriggerEventPublisherInterface::class, QueuedFlowTriggerEventPublisher::class);
        $this->app->bind(DelayResumeSchedulerInterface::class, QueuedDelayResumeScheduler::class);
        $this->app->bind(SendMessageTimeoutSchedulerInterface::class, QueuedSendMessageTimeoutScheduler::class);
        $this->app->bind(NodeUsageStatisticsInterface::class, NodeUsageStatisticsService::class);
        $this->app->bind(
            SubflowResumerInterface::class,
            fn ($app): DefaultSubflowResumer => new DefaultSubflowResumer(
                fn (): FlowEngineInterface => $app->make(FlowEngineInterface::class),
                $app->make(HistoryWriterFactory::class),
                $app->make(LoggerInterface::class),
            ),
        );
        $this->app->scoped(CallGraphRepository::class);
        $this->app->scoped(CallGraphValidator::class);
        $this->app->scoped(SubflowStarterService::class, fn ($app): SubflowStarterService => new SubflowStarterService(
            definitions: $app->make(FlowDefinitionRepositoryInterface::class),
            sessions: $app->make(FlowSessionRepositoryInterface::class),
            // Lazy: registering the subflow handler builds this service inside the
            // node-handler registry factory, and FlowEngine depends on that
            // registry — eager resolution would recurse.
            engineResolver: fn (): FlowEngineInterface => $app->make(FlowEngineInterface::class),
            graphResolver: $app->make(FlowGraphResolver::class),
            connection: $app->make(\Illuminate\Database\ConnectionInterface::class),
            historyWriterFactory: $app->make(HistoryWriterFactory::class),
        ));
        $this->app->scoped(SubflowTimeoutSweeper::class);

        $this->app->scoped(GlobalCommandExecutor::class);
        $this->app->scoped(
            GlobalCommandExecutorInterface::class,
            GlobalCommandExecutor::class
        );
        $this->app->scoped(SessionStateRouter::class);
        $this->app->scoped(DropPolicy::class);
        $this->app->scoped(
            DropPolicyInterface::class,
            DropPolicy::class
        );
        $this->app->scoped(MessageRouter::class);
        $this->app->scoped(TypingIndicatorService::class);
        $this->app->scoped(TypingHeartbeatRegistry::class);
        $this->app->bind(TenantTranslationServiceInterface::class, TenantTranslationService::class);
        $this->app->bind(AssistantTranslationServiceInterface::class, AssistantTranslationService::class);
        $this->app->singleton(TemplateResolver::class);
        $this->app->singleton(TemplateRenderer::class);
        $this->app->singleton(ExpressionEngineRegistry::class, function (): ExpressionEngineRegistry {
            $registry = new ExpressionEngineRegistry();
            $registry->register(new TemplateEngine());

            return $registry;
        });
        $this->app->singleton(DefaultHistoryWriter::class);
        $this->app->singleton(NoOpHistoryWriter::class);
        $this->app->singleton(HistoryWriterFactory::class);
        // Session lock stack. Parameters live in config/flow.php rather than
        // constructor defaults so TTL and retry budget are tunable per install.
        $this->app->singleton(SessionLockManager::class);
        $this->app->singleton(LockHeartbeat::class, fn ($app): LockHeartbeat => new LockHeartbeat(
            manager: $app->make(SessionLockManager::class),
            intervalSeconds: (int)$app->make('config')->get('flow.lock.heartbeat.interval_seconds', 10),
            extendToSeconds: (int)$app->make('config')->get('flow.lock.heartbeat.extend_to_seconds', 30),
        ));
        $this->app->singleton(LockAcquisitionPolicy::class, fn ($app): LockAcquisitionPolicy => new LockAcquisitionPolicy(
            manager: $app->make(SessionLockManager::class),
            maxAttempts: (int)$app->make('config')->get('flow.lock.acquisition_retries', 3),
            retryDelayMs: (int)$app->make('config')->get('flow.lock.retry_delay_ms', 2000),
            ttlSeconds: (int)$app->make('config')->get('flow.lock.ttl_seconds', 30),
        ));
        // Per-request slot holding this worker's claim: keeps the execution
        // guard re-entrant and gives the engine a handle to heartbeat.
        $this->app->scoped(SessionLockRegistry::class);
        $this->app->singleton(CallTransportRegistry::class, function ($app): CallTransportRegistry {
            $registry = new CallTransportRegistry();
            $registry->register($app->make(HttpTransport::class));
            $registry->register($app->make(HandlerTransport::class));

            return $registry;
        });
        $this->app->singleton(ActionHandlerRegistry::class);
        $this->app->singleton(RagAdapterRegistry::class);
        $this->app->singleton(HttpTransport::class);
        $this->app->singleton(HandlerTransport::class);
        $this->app->singleton(BuiltinCommandsRegistry::class);
        $this->app->singleton(CommandMatcher::class);
        $this->app->singleton(AssistantCommandsValidator::class);

        // System translation catalog is platform-global metadata — seeded once
        // at boot, then read-only for the rest of the lifecycle.
        $this->app->singleton(SystemTranslationCatalogInterface::class, InMemorySystemTranslationCatalog::class);

        $this->app->scoped(
            AfterCommitDispatcherInterface::class,
            fn ($app): AfterCommitDispatcherInterface => new ConnectionAfterCommitDispatcher(
                $app->make(DatabaseManager::class)->connection(),
            ),
        );

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
        // Scoped, not singleton: the guard reads the per-request lock registry.
        $this->app->scoped(FlowExecutionGuardInterface::class, FlowExecutionGuard::class);
        $this->app->scoped(DelayedSessionResumer::class);
    }

    /**
     * Populate the node handler registry with all Core handlers. Invoked from
     * the {@see NodeHandlerRegistry} singleton factory so registration stays
     * declarative and runs lazily on first resolve.
     *
     * Handlers are registered by class and built by the container on every
     * resolve, so their constructors take ordinary dependencies.
     */
    private function registerCoreNodeHandlers(NodeHandlerRegistry $registry): void
    {
        $handlers = [
            SendMessageNodeHandler::class,
            InputNodeHandler::class,
            BranchNodeHandler::class,
            DelayNodeHandler::class,
            AssignNodeHandler::class,
            CallNodeHandler::class,
            EmitEventNodeHandler::class,
            EndNodeHandler::class,
            RagQueryNodeHandler::class,
            SubflowNodeHandler::class,
            SetTagNodeHandler::class,
            NotifyNodeHandler::class,
            AuthRequestNodeHandler::class,
            LoopNodeHandler::class,
            LoopEndNodeHandler::class,
        ];

        foreach ($handlers as $handlerClass) {
            $registry->register($handlerClass);
        }
    }
}
