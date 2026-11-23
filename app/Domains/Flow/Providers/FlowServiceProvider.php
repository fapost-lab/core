<?php

declare(strict_types=1);

namespace App\Domains\Flow\Providers;

use App\Domains\Broadcasting\Events\BroadcastCompleted;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Contact\Events\ContactCreated;
use App\Domains\Contact\Events\ContactTagAdded;
use App\Domains\Contact\Events\ContactTagRemoved;
use App\Domains\Conversation\Events\OperatorRequestStateChanged;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Call\CallExecutionLedgerInterface;
use App\Domains\Flow\Call\CallLedgerCacheForgetter;
use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Call\Egress\AddressClassifier;
use App\Domains\Flow\Call\Egress\EgressGuardMiddleware;
use App\Domains\Flow\Call\Egress\EgressPolicy;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use App\Domains\Flow\Call\Egress\HostResolverInterface;
use App\Domains\Flow\Call\RedisCallExecutionLedger;
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
use App\Domains\Flow\Contracts\ActionHandlerFactoryInterface;
use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use App\Domains\Flow\Contracts\AssistantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\AssistantTranslationServiceInterface;
use App\Domains\Flow\Contracts\AudienceBroadcasterInterface;
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
use App\Domains\Flow\Contracts\FlowWebhookEndpointRepositoryInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\LanguageResolverInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\MutableDataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\NodeUsageStatisticsInterface;
use App\Domains\Flow\Contracts\OperatorRequestTimeoutSchedulerInterface;
use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Contracts\SendMessageTimeoutSchedulerInterface;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
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
use App\Domains\Flow\Handlers\RequestOperatorNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;
use App\Domains\Flow\Handlers\SetTagNodeHandler;
use App\Domains\Flow\Handlers\StartFlowNodeHandler;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Handlers\Support\TemplateResolver;
use App\Domains\Flow\Handlers\TemplateNodeHandler;
use App\Domains\Flow\Handlers\TemplateOutputNodeHandler;
use App\Domains\Flow\Handlers\WebhookResponseNodeHandler;
use App\Domains\Flow\History\DefaultHistoryWriter;
use App\Domains\Flow\History\HistoryWriterFactory;
use App\Domains\Flow\History\NoOpHistoryWriter;
use App\Domains\Flow\Listeners\ContinueAfterOperatorRequest;
use App\Domains\Flow\Listeners\PublishCoreEvents;
use App\Domains\Flow\Live\FlowSessionActivityObserver;
use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use App\Domains\Flow\Logging\FlowLogPartitionManager;
use App\Domains\Flow\Logging\FlowLogWriter;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\AutomationRunDeadline;
use App\Domains\Flow\Orchestration\AutomationRunDeadlineSweeper;
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
use App\Domains\Flow\Repositories\FlowWebhookEndpointRepository;
use App\Domains\Flow\Repositories\TenantEventRepository;
use App\Domains\Flow\Repositories\TenantTranslationRepository;
use App\Domains\Flow\Retention\AutomationRunRetention;
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
use App\Domains\Flow\State\StateNamespaceRegistry;
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
use App\Domains\Flow\Trace\NodeTraceRecorder;
use App\Domains\Flow\Translations\CoreSystemTranslations;
use App\Domains\Flow\Translations\InMemorySystemTranslationCatalog;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use App\Domains\Flow\Validation\ButtonLimitChecker;
use App\Domains\Flow\Validation\ChannelCapabilityChecker;
use App\Domains\Flow\Validation\ContactContextChecker;
use App\Domains\Flow\Validation\FlowGraphStructureValidator;
use App\Domains\Flow\Validation\FlowTriggerConfigValidator;
use App\Domains\Flow\Validation\ListSizeChecker;
use App\Domains\Flow\Validation\LoopConfigChecker;
use App\Domains\Flow\Validation\VariablePathConflictChecker;
use App\Domains\Flow\Webhook\FlowWebhookEndpointService;
use App\Domains\Flow\Webhook\FlowWebhookReceiver;
use App\Domains\Flow\Webhook\RedisWebhookDeliveryDedup;
use App\Domains\Flow\Webhook\RedisWebhookReplyStore;
use App\Domains\Flow\Webhook\RedisWebhookWaitSlots;
use App\Domains\Flow\Webhook\WebhookDeliveryDedupInterface;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;
use App\Domains\Flow\Webhook\WebhookRunEndReply;
use App\Domains\Flow\Webhook\WebhookSyncWait;
use App\Domains\Flow\Webhook\WebhookWaitSlotsInterface;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Messaging\Typing\TypingHeartbeatRegistry;
use App\Domains\Messaging\Typing\TypingIndicatorService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\PlatformSettingRegistry;
use App\Domains\Tenancy\Services\TenantUsageCounters;
use App\Domains\Tenancy\ValueObjects\PlatformSettingField;
use App\Infrastructure\Flow\BroadcastingAudienceBroadcaster;
use App\Infrastructure\Flow\CachedContentTranslator;
use App\Infrastructure\Flow\ConnectionAfterCommitDispatcher;
use App\Infrastructure\Flow\ContainerActionHandlerFactory;
use App\Infrastructure\Flow\ContainerNodeHandlerFactory;
use App\Infrastructure\Flow\DnsHostResolver;
use App\Infrastructure\Flow\FlowExecutionGuard;
use App\Infrastructure\Flow\QueuedDelayResumeScheduler;
use App\Infrastructure\Flow\QueuedOperatorRequestTimeoutScheduler;
use App\Infrastructure\Flow\QueuedSendMessageTimeoutScheduler;
use App\Infrastructure\Solution\ContainerSolutionAvailability;
use Fapost\Foundation\Flow\Contracts\StateNamespacesInterface;
use Fapost\Foundation\Flow\Contracts\TriggerResolverInterface;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Fapost\Foundation\Settings\Enums\PlatformSettingType;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
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

        FlowSession::observe(FlowSessionActivityObserver::class);

        // The Conversation → Flow seam of operator requests: Conversation announces, Flow continues the contact's side.
        Event::listen(OperatorRequestStateChanged::class, ContinueAfterOperatorRequest::class);

        // Core events as event triggers: the domains raise plain events, Flow publishes them.
        Event::listen(ContactCreated::class, [PublishCoreEvents::class, 'contactCreated']);
        Event::listen(ContactTagAdded::class, [PublishCoreEvents::class, 'contactTagAdded']);
        Event::listen(ContactTagRemoved::class, [PublishCoreEvents::class, 'contactTagRemoved']);
        Event::listen(BroadcastCompleted::class, [PublishCoreEvents::class, 'broadcastCompleted']);
        Event::listen(OperatorRequestStateChanged::class, [PublishCoreEvents::class, 'operatorRequestStateChanged']);

        // Registered the way a Solution registers its keys: from boot(), before the registry is frozen.
        $this->app->make(LimitRegistryInterface::class)->register(new LimitDefinition(
            key: FlowDraft::LIMIT_KEY,
            label: 'Flows',
            unit: 'flows',
            kind: LimitKind::Records,
            description: 'How many flows a tenant can have at a time; published versions of a flow do not count.',
        ));

        $this->app->make(TenantUsageCounters::class)->register(FlowDraft::LIMIT_KEY, FlowDraft::countForLimit(...));

        $this->app->make(LimitRegistryInterface::class)->register(new LimitDefinition(
            key: CallNodeHandler::LIMIT_KEY,
            label: 'Call executions',
            unit: 'calls',
            kind: LimitKind::PerPeriod,
            description: 'Executions of the call node, over any transport, and test calls from the builder. A call over the limit is not made.',
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: FlowEngineInterface::MAX_ITERATIONS_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.execution.max_iterations',
            fallback: 100,
            label: 'Steps per flow run',
            description: 'How many nodes one run of a flow may pass through outside loops before the session fails, and how many one loop iteration may take. Steps inside a loop count against the loop iterations instead.',
            group: 'Flow',
            unit: 'steps',
            min: 10,
            max: 10000,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: FlowEngineInterface::MAX_LOOP_ITERATIONS_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.execution.max_loop_iterations',
            fallback: 1000,
            label: 'Iterations per loop',
            description: sprintf(
                'How many times one loop node may run its body before the session fails: in one go for a conversation, over the whole run for an automation. A run never takes more than %s steps in all, so a loop with a long body stops sooner.',
                number_format(FlowEngineInterface::MAX_STEPS_PER_RUN),
            ),
            group: 'Flow',
            unit: 'iterations',
            min: 1,
            max: FlowEngineInterface::MAX_LOOP_ITERATIONS_LIMIT,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: RedisCallExecutionLedger::TTL_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.call.dedup_ttl_hours',
            fallback: 192,
            label: 'Call de-duplication window',
            description: 'How long the call node remembers a successful call, so that a retried step is not sent to the remote system again. Keep it above the Horizon failed-job retention (7 days): a step retried from the Horizon failed-job view until then is recognised; a retry after the window sends the call again with the same key.',
            group: 'Flow',
            unit: 'hours',
            min: 168,
            max: 720,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: FlowEngineInterface::SEGMENT_STEPS_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.automation.segment_steps',
            fallback: 50,
            label: 'Steps per automation segment',
            description: 'How many nodes an automation run passes through in one queue job before it saves its position and continues in a new job.',
            group: 'Flow',
            unit: 'steps',
            min: 1,
            max: 1000,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: FlowEngineInterface::SEGMENT_SECONDS_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.automation.segment_seconds',
            fallback: 20,
            label: 'Seconds per automation segment',
            description: 'How long an automation run executes in one queue job before it saves its position and continues in a new job. At most 30, so a segment plus its slowest node stays under the 60-second job timeout.',
            group: 'Flow',
            unit: 'seconds',
            min: 1,
            max: FlowEngineInterface::MAX_SEGMENT_SECONDS,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: AutomationRunDeadline::SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.automation.max_run_hours',
            fallback: AutomationRunDeadline::DEFAULT_HOURS,
            label: 'Maximum automation run duration',
            description: 'How long an automation run may live, from its start. A run still running or waiting past it — its job was lost, or it sleeps in a delay or waits longer than this — is failed with all its subflows, which cannot be undone. A change applies to runs that start afterwards.',
            group: 'Flow',
            unit: 'hours',
            min: AutomationRunDeadline::MIN_HOURS,
            max: AutomationRunDeadline::MAX_HOURS,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: AutomationRunRetention::SUCCEEDED_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.automation_runs.succeeded_retention_days',
            fallback: AutomationRunRetention::DEFAULT_SUCCEEDED_DAYS,
            label: 'Succeeded automation runs retention',
            description: 'How many days a finished automation run that succeeded is kept, with its history and logs, for every tenant. The daily flow:prune-automation-runs deletes older ones; deleting is irreversible. 0 keeps them forever. Conversation sessions are never pruned.',
            group: 'Flow',
            unit: 'days',
            min: 0,
            max: AutomationRunRetention::MAX_DAYS,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: AutomationRunRetention::FAILED_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.automation_runs.failed_retention_days',
            fallback: AutomationRunRetention::DEFAULT_FAILED_DAYS,
            label: 'Failed automation runs retention',
            description: 'How many days any other finished automation run (failed, expired, cancelled, terminated) is kept, with its history and logs, for every tenant. The daily flow:prune-automation-runs deletes older ones; deleting is irreversible. 0 keeps them forever.',
            group: 'Flow',
            unit: 'days',
            min: 0,
            max: AutomationRunRetention::MAX_DAYS,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: NodeTraceRecorder::PAYLOADS_SETTING,
            type: PlatformSettingType::Boolean,
            configKey: 'flow.trace.payloads',
            fallback: true,
            label: 'Node input and output in automation run traces',
            description: 'Whether the trace of an automation run keeps what each node received and produced (a call node: the request and the response body), masked and cut to 1 MB a row. Off, trace rows keep only the status, exit, duration and error. Conversation traces never keep them. A change applies to nodes that run afterwards.',
            group: 'Flow',
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: FlowWebhookReceiver::PER_TOKEN_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.webhook.per_token_per_minute',
            fallback: 120,
            label: 'Webhook trigger requests per minute',
            description: 'How many requests one webhook trigger URL accepts in a minute, signed or not, before it answers 429 Too Many Requests. Applies to every tenant.',
            group: 'Flow',
            unit: 'requests',
            min: 1,
            max: 6000,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: WebhookSyncWait::WAIT_SECONDS_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.webhook.sync_wait_seconds',
            fallback: WebhookSyncWait::MAX_WAIT_SECONDS,
            label: 'Webhook trigger answer wait',
            description: 'How long a request to a webhook trigger in "wait for the flow\'s answer" mode is held open for the flow to answer. Without an answer by then the sender gets 202 Accepted and the run goes on.',
            group: 'Flow',
            unit: 'seconds',
            min: 1,
            max: WebhookSyncWait::MAX_WAIT_SECONDS,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: WebhookSyncWait::INSTALLATION_WAITERS_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.webhook.sync_waiters_max',
            fallback: 5,
            label: 'Webhook requests waiting for an answer',
            description: 'How many webhook requests, across all tenants, may be held open at once waiting for their flow\'s answer. Each holds a PHP worker; a request over the limit gets 202 Accepted at once. Keep it well below the number of PHP-FPM workers.',
            group: 'Flow',
            unit: 'requests',
            min: 1,
            max: 100,
        ));

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: WebhookSyncWait::TENANT_WAITERS_SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'flow.webhook.sync_waiters_per_tenant',
            fallback: 2,
            label: 'Webhook requests waiting for an answer per tenant',
            description: 'How many webhook requests of one tenant may be held open at once waiting for their flow\'s answer; a request over the limit gets 202 Accepted at once.',
            group: 'Flow',
            unit: 'requests',
            min: 1,
            max: 100,
        ));

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
                $this->app->make(StateNamespaceRegistry::class)->freeze();
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
                $app->make(\App\Domains\Messaging\OutboundVolumeGate::class),
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
        $this->app->bind(
            \App\Domains\Flow\Contracts\AutomationContinuationSchedulerInterface::class,
            \App\Infrastructure\Flow\QueuedAutomationContinuationScheduler::class,
        );
        $this->app->bind(SendMessageTimeoutSchedulerInterface::class, QueuedSendMessageTimeoutScheduler::class);
        $this->app->bind(AudienceBroadcasterInterface::class, BroadcastingAudienceBroadcaster::class);
        $this->app->bind(
            \App\Domains\Flow\Contracts\ContactFlowStartDispatcherInterface::class,
            \App\Infrastructure\Flow\QueuedContactFlowStartDispatcher::class,
        );
        $this->app->bind(OperatorRequestTimeoutSchedulerInterface::class, QueuedOperatorRequestTimeoutScheduler::class);
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
        $this->app->bind(ChannelCapabilityChecker::class);
        $this->app->bind(ButtonLimitChecker::class);
        $this->app->bind(FlowGraphStructureValidator::class);
        $this->app->bind(ContactContextChecker::class);
        $this->app->bind(LoopConfigChecker::class);
        $this->app->bind(ListSizeChecker::class);
        $this->app->bind(VariablePathConflictChecker::class);
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
        $this->app->scoped(AutomationRunDeadline::class);
        $this->app->scoped(AutomationRunDeadlineSweeper::class);

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
        // Per-job budget of an automation run's execution segment, shared by nested engine calls.
        $this->app->scoped(\App\Domains\Flow\Services\ExecutionSegment::class);
        // The event chain depth of the session running now, for events raised inside it.
        $this->app->scoped(\App\Domains\Flow\Services\EventChainContext::class);
        $this->app->singleton(CallTransportRegistry::class, function ($app): CallTransportRegistry {
            $registry = new CallTransportRegistry();
            $registry->register($app->make(HttpTransport::class));
            $registry->register($app->make(HandlerTransport::class));

            return $registry;
        });
        // Action handlers are kept as classes and built per call in the current scope. Whether the action of a
        // Solution may run for the current tenant is asked through SolutionAvailabilityInterface; the answer comes from
        // App\Infrastructure\Solution, the seam that composes Flow with the Solution domain.
        $this->app->singleton(ActionHandlerFactoryInterface::class, ContainerActionHandlerFactory::class);
        $this->app->singleton(SolutionAvailabilityInterface::class, ContainerSolutionAvailability::class);
        // Image-level: the built-in namespaces plus those of Solutions with `state: true`, filled while booting.
        $this->app->singleton(StateNamespaceRegistry::class);
        $this->app->singleton(StateNamespacesInterface::class, static fn ($app): StateNamespaceRegistry => $app->make(StateNamespaceRegistry::class));
        $this->app->singleton(ActionHandlerRegistry::class);
        $this->app->singleton(RagAdapterRegistry::class);
        // Egress guard of the call node. The policy is read once into a value object, so the
        // singletons below never hold the config repository (worker-safety).
        $this->app->singleton(EgressPolicy::class, function ($app): EgressPolicy {
            $config = $app->make('config');
            $policy = EgressPolicy::fromConfig(
                is_string($config->get('flow.egress.allow')) ? $config->get('flow.egress.allow') : null,
                (int) $config->get('flow.egress.max_redirects', 5),
                is_string($config->get('flow.egress.proxy')) ? $config->get('flow.egress.proxy') : null,
                [
                    parse_url((string) $config->get('app.url'), PHP_URL_HOST) ?: null,
                    is_string($config->get('tenancy.base_domain')) ? $config->get('tenancy.base_domain') : null,
                ],
            );
            $logger = $app->make(LoggerInterface::class);

            if ($policy->allowsEverything()) {
                $logger->warning('FLOW_EGRESS_ALLOW covers every address: the call node egress guard is effectively off.');
            }

            if ([] !== $policy->invalidEntries) {
                $logger->warning('FLOW_EGRESS_ALLOW has entries that are neither a network nor a host name; they are ignored.', [
                    'entries' => $policy->invalidEntries,
                ]);
            }

            return $policy;
        });
        $this->app->singleton(AddressClassifier::class);
        $this->app->singleton(HostResolverInterface::class, DnsHostResolver::class);
        $this->app->singleton(EgressGuardMiddleware::class);
        $this->app->singleton(GuardedHttpClient::class);
        // Scoped: it reads a platform setting.
        $this->app->scoped(CallExecutionLedgerInterface::class, RedisCallExecutionLedger::class);
        $this->app->tag(CallLedgerCacheForgetter::class, 'tenancy.cache_forgetters');
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
        // Reads the scoped platform settings; built per scope like the engine that calls it.
        $this->app->scoped(NodeTraceRecorder::class);
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
        $this->app->scoped(FlowWebhookEndpointRepositoryInterface::class, FlowWebhookEndpointRepository::class);
        $this->app->scoped(WebhookDeliveryDedupInterface::class, RedisWebhookDeliveryDedup::class);
        $this->app->scoped(FlowWebhookEndpointService::class);
        $this->app->scoped(FlowWebhookReceiver::class);
        $this->app->scoped(WebhookReplyStoreInterface::class, RedisWebhookReplyStore::class);
        $this->app->scoped(WebhookWaitSlotsInterface::class, RedisWebhookWaitSlots::class);
        $this->app->scoped(WebhookSyncWait::class);
        $this->app->scoped(WebhookRunEndReply::class);
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
                $app->make(ChannelRegistryInterface::class),
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
            StartFlowNodeHandler::class,
            SetTagNodeHandler::class,
            NotifyNodeHandler::class,
            AuthRequestNodeHandler::class,
            LoopNodeHandler::class,
            LoopEndNodeHandler::class,
            RequestOperatorNodeHandler::class,
            TemplateNodeHandler::class,
            TemplateOutputNodeHandler::class,
            WebhookResponseNodeHandler::class,
        ];

        foreach ($handlers as $handlerClass) {
            $registry->register($handlerClass);
        }
    }
}
