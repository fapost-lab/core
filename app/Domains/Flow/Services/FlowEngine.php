<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use App\Domains\Flow\Contracts\DelayResumeSchedulerInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\LanguageResolverInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Exceptions\FlowExecutionLimitExceededException;
use App\Domains\Flow\Exceptions\HandlerNotFoundException;
use App\Domains\Flow\Exceptions\InvalidFlowGraphException;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Flow\Exceptions\SessionLockLostException;
use App\Domains\Flow\Handlers\EndNodeHandler;
use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Logging\FlowLogEntry;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Logging\FlowLogWriter;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\AutomationRunDeadline;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\Trace\LoopIterations;
use App\Domains\Flow\Trace\NodeTraceRecorder;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use DateTimeImmutable;
use Fapost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use Fapost\Foundation\Analytics\DTO\AnalyticsEvent;
use Fapost\Foundation\Analytics\Enums\AnalyticsEventType;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\NodeExecutionContext as FoundationNodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\StateNamespacesInterface;
use Fapost\Foundation\Flow\Enums\StateNamespace;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class FlowEngine implements FlowEngineInterface
{
    public function __construct(
        private FlowGraphResolver $graphResolver,
        private NodeHandlerRegistryInterface $registry,
        private FlowSessionRepositoryInterface $sessions,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowSessionPersister $persister,
        private FlowLogWriter $logWriter,
        private AnalyticsWriterInterface $analyticsWriter,
        private CurrentAssistantInterface $currentAssistant,
        private ContactServiceInterface $contactService,
        private LanguageResolverInterface $languageResolver,
        private ConnectionInterface $connection,
        private AfterCommitDispatcherInterface $afterCommitDispatcher,
        private PlatformSettingsReaderInterface $platformSettings,
        private \App\Domains\Flow\Expression\ExpressionEngineRegistry $expressionEngines,
        private \App\Domains\Flow\Contracts\DataAccessorRegistryInterface $dataAccessors,
        private \App\Domains\Flow\History\HistoryWriterFactory $historyWriterFactory,
        private \App\Domains\Flow\Subflow\SubflowResumerInterface $subflowResumer,
        private \App\Domains\Messaging\Typing\TypingHeartbeatRegistry $typingHeartbeat,
        private LoggerInterface $logger,
        private VariableSchemaRegistryInterface $schemaRegistry,
        private \App\Domains\Flow\Concurrency\SessionLockRegistry $sessionLock,
        private \App\Domains\Flow\Concurrency\LockHeartbeat $lockHeartbeat,
        private \App\Domains\Flow\State\ChannelStateProjector $channelProjector,
        private DelayResumeSchedulerInterface $delayResumeScheduler,
        private SolutionAvailabilityInterface $solutionAvailability,
        private StateNamespacesInterface $stateNamespaces,
        private ExecutionSegment $segment,
        private SegmentHandOff $handOff,
        private AutomationRunDeadline $runDeadline,
        private NodeTraceRecorder $trace,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function start(
        FlowDefinition $definition,
        ?Contact $contact,
        array $initialState = [],
        ?FlowRunOrigin $origin = null,
    ): FlowSession {
        $origin ??= null === $contact ? FlowRunOrigin::automation() : FlowRunOrigin::conversation();

        if (null === $contact && FlowRunKind::Automation !== $origin->kind) {
            throw new InvalidArgumentException('A flow session without a contact can only be an automation run.');
        }

        $entry = $this->graphResolver->resolveEntryNode($definition);

        // A conversation belongs to the assistant the contact talks to; a contactless run only to the one its origin
        // names, never to whichever assistant the current job happened to resolve.
        $assistantId = null !== $contact
            ? (string)$this->currentAssistant->get()->getKey()
            : $origin->assistantId;

        $baseState = $this->buildBaseState($definition, $contact, $assistantId, $initialState);

        // An automation run's deadline is frozen at its start; a later change of the setting never moves it.
        $deadlineAt = FlowRunKind::Automation === $origin->kind ? $this->runDeadline->for(now()) : null;

        $session = $this->connection->transaction(fn (): FlowSession => $this->sessions->create([
            'tenant_id'          => null !== $contact ? $contact->tenant_id : $definition->tenant_id,
            'assistant_id'       => $assistantId,
            'contact_id'         => $contact?->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'current_node_id'    => $entry,
            'state'              => $baseState,
            'status'             => FlowSessionStatus::Active,
            'run_kind'           => $origin->kind,
            'trigger_id'         => $origin->triggerId,
            'fire_key'           => $origin->fireKey,
            'deadline_at'        => $deadlineAt,
            'version'            => 1,
        ]));

        $this->afterCommit(function () use ($session): void {
            $this->analyticsWriter->record(
                new AnalyticsEvent(
                    tenantId: (string)$session->tenant_id,
                    eventType: AnalyticsEventType::FlowStarted,
                    payload: ['session_id' => (string)$session->getKey()],
                    occurredAt: new DateTimeImmutable(),
                )
            );
        });

        $this->executeLoop($definition, $session, null, $contact);
        $session->refresh();

        return $session;
    }

    /**
     * @throws Throwable
     */
    public function resume(FlowSession $session, IncomingMessage $message): FlowSession
    {
        $session->refresh();

        $definition = $this->definitions->findById($session->flow_definition_id);
        $contact    = $this->sessionContact($session);

        $this->executeLoop($definition, $session, $message, $contact);
        $session->refresh();

        return $session;
    }

    /**
     * @throws Throwable
     */
    public function resumeFromNode(
        FlowDefinition $definition,
        Contact $contact,
        string $nodeId,
        string $outputHandle,
        array $initialState = [],
    ): FlowSession {
        $nextNodeId = $this->graphResolver->resolveNextNode($definition, $nodeId, $outputHandle);

        $assistant = $this->currentAssistant->get();

        $baseState = $this->buildBaseState($definition, $contact, (string)$assistant->getKey(), $initialState);

        $status  = null !== $nextNodeId ? FlowSessionStatus::Active : FlowSessionStatus::Completed;
        $session = $this->connection->transaction(fn (): FlowSession => $this->sessions->create([
            'tenant_id'          => $contact->tenant_id,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'current_node_id'    => $nextNodeId,
            'state'              => $baseState,
            'status'             => $status,
            'version'            => 1,
        ]));

        $this->afterCommit(function () use ($session): void {
            $this->analyticsWriter->record(
                new AnalyticsEvent(
                    tenantId: (string)$session->tenant_id,
                    eventType: AnalyticsEventType::FlowStarted,
                    payload: ['session_id' => (string)$session->getKey()],
                    occurredAt: new DateTimeImmutable(),
                )
            );
        });

        if (null !== $nextNodeId) {
            $this->executeLoop($definition, $session, null, $contact);
            $session->refresh();
        }

        return $session;
    }

    public function runSession(FlowSession $session, bool $resumedAfterDelay = false): FlowSession
    {
        $session->refresh();

        $definition = $this->definitions->findById($session->flow_definition_id);
        $contact    = $this->sessionContact($session);

        $this->executeLoop($definition, $session, null, $contact, $resumedAfterDelay);
        $session->refresh();

        return $session;
    }

    public function resumeAfterSubflow(FlowSession $parent, string $sourceHandle, array $outputs = []): FlowSession
    {
        $parent->refresh();

        $definition = $this->definitions->findById($parent->flow_definition_id);
        $contact    = $this->sessionContact($parent);
        $resumeNode = (string) ($parent->current_node_id ?? '');

        if ('' === $resumeNode) {
            // Parent was already advanced past the subflow node — nothing to do.
            return $parent;
        }

        $nextNodeId = $this->graphResolver->resolveNextNode($definition, $resumeNode, $sourceHandle);

        $this->connection->transaction(function () use ($parent, $nextNodeId, $outputs): void {
            $patch = [
                'status'          => null !== $nextNodeId ? FlowSessionStatus::Active : FlowSessionStatus::Completed,
                'current_node_id' => $nextNodeId,
            ];

            if ([] !== $outputs) {
                $patch['state'] = \App\Domains\Flow\Subflow\SubflowMapping::withSessionVariables($parent->state ?? [], $outputs);
            }

            try {
                $parent->saveWithOptimisticLock($patch);
            } catch (OptimisticLockConflictException $exception) {
                throw FlowConcurrencyException::forSession((string)$parent->getKey(), $exception);
            }
        });

        if (null !== $nextNodeId) {
            $this->executeLoop($definition, $parent, null, $contact);
        } else {
            // No edge for that handle: the parent just ran off its graph. When it is itself a child, its own
            // parent must not keep waiting for it (a plain finish, as for any session that runs off its graph).
            $this->subflowResumer->resumeIfChild($parent, EndStatus::Success->value);
        }

        $parent->refresh();

        return $parent;
    }

    /**
     * The contact of a session, or null for a contactless run.
     */
    private function sessionContact(FlowSession $session): ?Contact
    {
        return null !== $session->contact_id ? $this->contactService->findById($session->contact_id) : null;
    }

    private function afterCommit(callable $callback): void
    {
        $this->afterCommitDispatcher->afterCommit($callback);
    }

    /**
     * Engine-owned `system.*` seed for a freshly created session. Caller state
     * (trigger payload, subflow input) is merged last so it can extend, but
     * never silently drop, the engine's own keys.
     *
     * @param  array<string, mixed>  $initialState
     *
     * @return array<string, mixed>
     */
    private function buildBaseState(
        FlowDefinition $definition,
        ?Contact $contact,
        ?string $assistantId,
        array $initialState,
    ): array {
        // A contactless run has no contact to record and no channel the contact talked on.
        $system = array_filter([
            SystemStateKeys::STARTED_AT_LEAF  => now()->toIso8601String(),
            'flow_definition_id'              => (string)$definition->getKey(),
            'contact_id'                      => null !== $contact ? (string)$contact->getKey() : null,
            SystemStateKeys::RETRY_COUNT_LEAF => 0,
        ], static fn (mixed $value): bool => null !== $value);

        $channel = null !== $contact && null !== $assistantId
            ? $this->channelProjector->project((string)$contact->getKey(), $assistantId)
            : [];

        if ([] !== $channel) {
            $system[SystemStateKeys::CHANNEL_LEAF] = $channel;
        }

        return array_replace_recursive([StateNamespace::System->value => $system], $initialState);
    }

    /**
     * Runs the session's steps inside the current execution segment.
     *
     * Every call — the outermost one of a job and the ones nested in it (a subflow child inside its parent's step, a
     * parent resumed inside its child's last step) — shares one {@see ExecutionSegment}. An automation run whose
     * segment ran out stops at its saved position; once the outermost call returns, the run is handed to a new job
     * ({@see SegmentHandOff}). Never from a nested call: on a sync queue the continuation would run inside the
     * segment that just ran out.
     *
     * @throws Throwable
     */
    private function executeLoop(
        FlowDefinition $definition,
        FlowSession $session,
        ?IncomingMessage $incoming,
        ?Contact $contact,
        bool $resumedAfterDelay = false,
    ): void {
        $this->segment->enter();

        try {
            $this->runSteps($definition, $session, $incoming, $contact, $resumedAfterDelay);
        } catch (Throwable $exception) {
            // The job fails and is retried from the saved position; no continuation is queued.
            $this->segment->leave();

            throw $exception;
        }

        $stopped = $this->segment->leave();

        if (null !== $stopped) {
            // The new segment id goes on the run's root, and the job is queued only after that commit.
            $this->handOff->handOn($stopped);
        }
    }

    /**
     * @throws Throwable
     */
    private function runSteps(
        FlowDefinition $definition,
        FlowSession $session,
        ?IncomingMessage $incoming,
        ?Contact $contact,
        bool $resumedAfterDelay = false,
    ): void {
        $automation = FlowRunKind::Automation === $session->run_kind;

        $maxSteps          = $this->platformSettings->integer(FlowEngineInterface::MAX_ITERATIONS_SETTING);
        $maxLoopIterations = $this->platformSettings->integer(FlowEngineInterface::MAX_LOOP_ITERATIONS_SETTING);

        // An automation run counts over all its segments: a runaway would otherwise start a fresh count in every
        // segment and dispatch itself forever. A conversation keeps one budget per session for the outermost call, so
        // a parent resumed after its child ended — a nested call — keeps its loop's count and the call's clock.
        $budget = $automation
            ? StepBudget::forAutomationRun(
                $maxSteps,
                $maxLoopIterations,
                (int) $session->step_count,
                (int) $session->loop_step_count,
                (int) $session->loop_iteration_step_count,
                $definition->nodes ?? [],
                $session->state ?? [],
            )
            : $this->segment->conversationBudget(
                (string) $session->getKey(),
                fn (): StepBudget => StepBudget::forConversation($maxSteps, $maxLoopIterations, $this->segment->startedAt()),
            );
        $incomingStep          = $incoming;
        $resumedAfterDelayStep = $resumedAfterDelay;

        while (true) {
            $limit = $budget->charge((string) ($session->current_node_id ?? ''));

            if (null !== $limit) {
                if ($this->failAtStepLimit($definition, $session, $limit)) {
                    return;
                }

                throw $limit;
            }

            if ($automation && $this->segmentRanOut($session)) {
                $this->segment->stopAt($session);

                break;
            }

            $currentNodeId = $session->current_node_id;

            if (null === $currentNodeId || '' === $currentNodeId) {
                throw InvalidFlowGraphException::sessionHasNoCurrentNode();
            }

            $node = $this->graphResolver->findNode($definition, $currentNodeId);

            $nodeId = $node['id'] ?? null;

            if (! is_string($nodeId) || '' === $nodeId) {
                throw InvalidFlowGraphException::missingNode($currentNodeId);
            }

            $type = $node['type'] ?? null;

            if (! is_string($type) || '' === $type) {
                throw InvalidFlowGraphException::nodeMissingType($nodeId);
            }

            $version = $this->resolveNodeVersion($node);

            // The node trace (flow_session_history, always written, outside the step's transaction): the loops the node
            // runs in are read from the state it starts with.
            $iterations = LoopIterations::of($definition->nodes ?? [], $session->state ?? []);
            $this->trace->entered($session, $nodeId, $type, $version, $iterations);
            $startedAt = hrtime(true);

            // A node of a Solution that is switched off for this tenant, or whose package is gone, does not
            // build its handler: the node takes its `error` exit, like a handler that reports an error. Core
            // types without a handler keep failing hard below.
            $result = $this->solutionGate($type, $version);

            if (null === $result) {
                try {
                    $handler = $this->registry->resolve($type, $version);
                } catch (LogicException $exception) {
                    $notFound = HandlerNotFoundException::forTypeAndVersion($type, $version, $exception);
                    $this->trace->failed($session, $nodeId, $type, $version, $notFound::class, $notFound->getMessage(), null, $iterations);

                    throw $notFound;
                }

                // A contactless run gives the handler no platform and no contact id (null); publishing keeps a node that
                // needs a contact out of such a flow (ContactContextChecker).
                $platform         = $incomingStep?->platform ?? $contact?->platform;
                $resolvedLanguage = $this->languageResolver->resolve($contact, $session);

                $idempotencyKey = null !== $incomingStep
                    ? $incomingStep->updateId . '|' . $session->getKey()
                    : $session->getKey() . ':' . $currentNodeId . ':' . $session->version;

                $engineId = '' !== (string) ($definition->expression_engine ?? '')
                    ? (string) $definition->expression_engine
                    : \App\Domains\Flow\Expression\Engines\TemplateEngine::ID;
                $expressionEngine = $this->expressionEngines->has($engineId)
                    ? $this->expressionEngines->get($engineId)
                    : $this->expressionEngines->get(\App\Domains\Flow\Expression\Engines\TemplateEngine::ID);

                $stateReader = new \App\Domains\Flow\State\Readers\ScopedStateReader(
                    sessionState: $session->state ?? [],
                    contact: $contact,
                    accessors: $this->dataAccessors,
                    namespaces: $this->stateNamespaces,
                );

                $schemaRegistry = $this->schemaRegistry;
                $contactWriter  = new \App\Domains\Flow\State\Writers\ContactWriter(
                    contact: $contact,
                    sessionId: (string)$session->getKey(),
                    nodeId: $nodeId,
                    connection: $this->connection,
                    historyWriter: $this->historyWriterFactory->for($definition),
                    schemaRegistryResolver: static fn () => $schemaRegistry,
                );

                $handlerContext = new FoundationNodeExecutionContext(
                    tenantId: $session->tenant_id,
                    contactId: $session->contact_id,
                    sessionId: (string)$session->getKey(),
                    nodeId: $nodeId,
                    idempotencyKey: $idempotencyKey,
                    platform: $platform,
                    resolvedLanguage: $resolvedLanguage,
                    incoming: $incomingStep,
                    stateReader: $stateReader,
                    contactWriter: $contactWriter,
                    expressionEngine: $expressionEngine,
                    resumedAfterDelay: $resumedAfterDelayStep,
                );

                // Keep the user-visible "typing…" indicator alive across multi-node
                // execution: providers like Telegram drop the chat action after ~5s.
                // No-op when no typing session is registered (e.g. queue jobs that
                // bypass the routing pipeline).
                $this->typingHeartbeat->current()?->refresh();

                // Same cadence, different purpose: extend the session lock TTL so a
                // long chain of nodes does not outlive the claim. Throws when the
                // claim has drifted — see refreshSessionLock().
                $this->refreshSessionLock();

                try {
                    $result = $handler->execute($node, $session->state ?? [], $handlerContext);
                } catch (VolumeLimitReachedException $limitReached) {
                    $this->traceFailure($session, $nodeId, $type, $version, $limitReached, $startedAt, $iterations);

                    // The node has no `error` exit to take (those catch this themselves). Retrying the job
                    // would only ask the operator again, so the session stops here and the job succeeds.
                    $this->markSessionFailed($session, $nodeId, $type, $version, $limitReached);

                    // A child must hand control back to its parent, as it does when it ends: the parent's
                    // `failed` exit is taken now instead of the parent waiting for the timeout sweeper.
                    $this->subflowResumer->resumeIfChild($session, EndStatus::Failed->value);

                    return;
                } catch (Throwable $handlerException) {
                    $this->traceFailure($session, $nodeId, $type, $version, $handlerException, $startedAt, $iterations);

                    // Hand back only when the failure was recorded: if another worker moved the session on,
                    // the parent is theirs to resume. A child that handed back is dealt with and does not
                    // rethrow: rethrowing would fail the job, and its retry would feed the same message to the
                    // already-advanced parent (or, in a synchronous start, make the parent's own run fail).
                    if ($this->markSessionFailed($session, $nodeId, $type, $version, $handlerException)
                        && $this->handBackFailedChild($session)) {
                        return;
                    }

                    throw $handlerException;
                }
            }

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

            // A loop about to start one iteration too many fails before its body runs; the node has no side effects.
            $limit = $budget->record($node, $result, $session->state ?? []);

            if (null !== $limit) {
                if ($this->failAtStepLimit($definition, $session, $limit)) {
                    return;
                }

                throw $limit;
            }

            $nextNodeId = null;

            if (NodeExecutionStatus::Executed === $result->status && null !== $result->sourceHandle) {
                $nextNodeId = $this->graphResolver->resolveNextNode($definition, $nodeId, $result->sourceHandle);
            }

            // LoopEnd: navigate back to the parent Loop node without a graph edge (spec §4.3).
            if (LoopEndNodeHandler::TYPE === $type && NodeExecutionStatus::Executed === $result->status) {
                $rawConfig  = is_array($node['config'] ?? null) ? $node['config'] : [];
                $loopNodeId = is_string($rawConfig['loop_node_id'] ?? null) ? $rawConfig['loop_node_id'] : null;

                if (null !== $loopNodeId) {
                    $nextNodeId = $loopNodeId;
                }
            }

            $isEndNode = EndNodeHandler::TYPE === $type;
            $endStatus = null;

            if ($isEndNode && NodeExecutionStatus::Finished === $result->status) {
                $rawEndStatus = $result->metadata[EndNodeHandler::END_STATUS_META] ?? null;
                $endStatus    = (is_string($rawEndStatus) ? EndStatus::tryFrom($rawEndStatus) : null)
                                ?? EndStatus::Success;
                $endStatus = $endStatus->value;
            }

            // Subflow special case: SubflowStarter pauses the parent (paused_subflow)
            // and runs the child synchronously. If the child reaches an end node
            // during this execution tick, DefaultSubflowResumer will already have
            // advanced the parent to its post-subflow position. Trying to persist
            // the stale result here would either lose that progress or
            // raise an OptimisticLockConflict. Skip persistence in that scenario.
            $skipPersist = false;
            // Whether a nested run of this session counted steps of its own while this step ran: the parent resumed
            // by its child's end inside the subflow node. That run went on from here and wrote the iteration count
            // that holds now, so this frame's count is stale.
            $nestedRunCounted = false;
            if (SubflowNodeHandler::TYPE === $type) {
                $latest = FlowSession::query()->find($session->getKey());
                if (null !== $latest && $latest->version !== $session->version) {
                    $skipPersist      = true;
                    $nestedRunCounted = (int) $latest->step_count !== (int) $session->step_count
                        || (int) $latest->loop_step_count !== (int) $session->loop_step_count;
                    $session->setRawAttributes($latest->getAttributes(), true);
                }
            }

            $stepInLoop     = $budget->lastStepInLoop();
            $iterationSteps = $nestedRunCounted ? null : $budget->iterationSteps();

            $this->connection->transaction(function () use ($session, $result, $nextNodeId, $endStatus, $skipPersist, $type, $nodeId, $automation, $stepInLoop, $iterationSteps): void {
                if ($automation) {
                    $this->countRunStep($session, $stepInLoop ? 'loop_step_count' : 'step_count', $iterationSteps);
                }

                if ($skipPersist) {
                    // No-op: state already reflects the most recent write.
                } elseif (null !== $endStatus) {
                    $this->persister->persistEnd($session, $result, $endStatus, $type, $nodeId);
                } else {
                    $this->persister->persist($session, $result, $nextNodeId, $type, $nodeId);

                    // Same path the `delay` node uses (§ same sync-queue refusal):
                    // the job is dispatched with `->afterCommit()`, so calling this
                    // inside the transaction is safe — it only fires once the
                    // `paused` marker just written by persist() above is durable.
                    if (NodeExecutionStatus::Delayed === $result->status && null !== $result->resumeAt) {
                        $this->delayResumeScheduler->schedule(
                            (string)$session->tenant_id,
                            (string)$session->getKey(),
                            $nodeId,
                            $result->resumeAt,
                        );
                    }
                }

                $analyticsEventType = $this->resolveAnalyticsEventType($result, $nextNodeId, $endStatus);
                if (null !== $analyticsEventType) {
                    $this->afterCommit(function () use ($analyticsEventType, $session): void {
                        $this->analyticsWriter->record(
                            new AnalyticsEvent(
                                tenantId: (string)$session->tenant_id,
                                eventType: $analyticsEventType,
                                payload: ['session_id' => (string)$session->getKey()],
                                occurredAt: new DateTimeImmutable(),
                            )
                        );
                    });
                }
            });

            // Outside the transaction and deliberately non-fatal: the node's
            // side effects (a sent message) have already happened, so letting a
            // telemetry failure bubble up would fail the job, retry it, and
            // deliver the message again.
            $this->writeLogSafely($this->buildLogEntry($session, $node, $result, $nextNodeId));
            $this->trace->completed(
                $session,
                $nodeId,
                $type,
                $version,
                $result,
                $nextNodeId,
                $durationMs,
                $incomingStep,
                $resumedAfterDelayStep,
                $iterations,
            );

            if (null !== $endStatus) {
                $this->subflowResumer->resumeIfChild($session, $endStatus);
            } elseif (!$skipPersist) {
                // No `end` node was reached: the flow ran off its graph (a plain finish) or a node reported a
                // failure. Either way a child must still hand control back to its parent.
                match ($session->status) {
                    FlowSessionStatus::Completed => $this->subflowResumer->resumeIfChild($session, EndStatus::Success->value),
                    FlowSessionStatus::Failed    => $this->subflowResumer->resumeIfChild($session, EndStatus::Failed->value),
                    default                      => null,
                };
            }

            $this->segment->countStep();

            $incomingStep          = null;
            $resumedAfterDelayStep = false;

            if (in_array($result->status, [
                NodeExecutionStatus::Waiting,
                NodeExecutionStatus::Delayed,
                NodeExecutionStatus::Failed,
                NodeExecutionStatus::Finished,
            ], true)) {
                break;
            }

            if (null === $nextNodeId) {
                break;
            }
        }
    }

    /**
     * Hands a child that failed back to its parent's `failed` exit, exactly as an ended child does. What the
     * parent's resumed run raises is the parent's own failure (it has already recorded it) and propagates
     * unchanged: swallowing it here would let the child rethrow its own exception, retry the job and repeat
     * the parent's side effects.
     *
     * @return bool true when the session is a child and its parent was resumed
     */
    private function handBackFailedChild(FlowSession $session): bool
    {
        $parentId = $session->parent_session_id;

        if (!is_string($parentId) || '' === $parentId) {
            return false;
        }

        $this->subflowResumer->resumeIfChild($session, EndStatus::Failed->value);

        return true;
    }

    /**
     * Fails a session that crossed a step limit. A child then hands control back to its parent, as it does when it
     * ends, and the failure is dealt with (like the volume limit), so the job does not fail and retry; the caller
     * throws the limit for any other session.
     *
     * @return bool true when the session was a child and its parent was resumed
     */
    private function failAtStepLimit(FlowDefinition $definition, FlowSession $session, FlowExecutionLimitExceededException $limit): bool
    {
        // Before the failure is persisted, so the trace keeps it even if the persist fails.
        $this->trace->failed(
            $session,
            (string) ($session->current_node_id ?? ''),
            null,
            null,
            NodeTraceRecorder::STEP_LIMIT_ERROR,
            $limit->getMessage(),
            null,
            LoopIterations::of($definition->nodes ?? [], $session->state ?? []),
        );

        $this->persistStepLimitFailure($session);

        // Nothing rethrows a child's limit, so its message would otherwise be lost.
        $this->logger->warning('flow.step_limit_reached', [
            'session_id' => (string) $session->getKey(),
            'message'    => $limit->getMessage(),
        ]);

        return $this->handBackFailedChild($session);
    }

    /**
     * Record the failure of a session that reached the step limit; the caller hands a child back or stops the job.
     */
    private function persistStepLimitFailure(FlowSession $session): void
    {
        $this->connection->transaction(function () use ($session): void {
            try {
                $session->saveWithOptimisticLock([
                    'status' => FlowSessionStatus::Failed,
                ]);
            } catch (OptimisticLockConflictException $exception) {
                throw FlowConcurrencyException::forSession((string)$session->getKey(), $exception);
            }

            $this->afterCommit(function () use ($session): void {
                $this->analyticsWriter->record(
                    new AnalyticsEvent(
                        tenantId: (string)$session->tenant_id,
                        eventType: AnalyticsEventType::FlowFailed,
                        payload: ['session_id' => (string)$session->getKey()],
                        occurredAt: new DateTimeImmutable(),
                    )
                );
            });
        });
    }

    /**
     * True when an `active` automation session must stop before its next node: the segment ran at least one step and
     * reached its step or time budget. Its position — the next node — is already saved.
     */
    private function segmentRanOut(FlowSession $session): bool
    {
        if (FlowSessionStatus::Active !== $session->status) {
            return false;
        }

        // The bounds hold for an installation default too: the settings store checks only a stored value, and a
        // budget past the job timeout would have every attempt killed mid-segment.
        return $this->segment->exhausted(
            max(1, $this->platformSettings->integer(FlowEngineInterface::SEGMENT_STEPS_SETTING)),
            min(
                FlowEngineInterface::MAX_SEGMENT_SECONDS,
                max(1, $this->platformSettings->integer(FlowEngineInterface::SEGMENT_SECONDS_SETTING)),
            ),
        );
    }

    /**
     * One more step of an automation session, in the transaction of the step itself: in `step_count` outside a loop
     * body, in `loop_step_count` inside one; and the steps of the loop iteration in progress after it
     * (`loop_iteration_step_count`, 0 once a `loop_end` or the loop's exit ran), written as a value rather than
     * incremented, so the one step a killed segment repeats sets it again instead of counting twice. The columns are
     * not part of the optimistic lock: the step's own write bumps the version.
     *
     * A null `$iterationSteps` leaves the iteration count as it is: a subflow step whose parent a nested run already
     * took further wrote it there, and this frame's value predates that run. Such a run starts from the count before
     * the subflow step, so until its next `loop_end` it counts that one step short — on the side that never fails a
     * run early.
     *
     * @param  'step_count'|'loop_step_count'  $column
     */
    private function countRunStep(FlowSession $session, string $column, ?int $iterationSteps): void
    {
        $extra = null !== $iterationSteps ? ['loop_iteration_step_count' => $iterationSteps] : [];

        $session->newQuery()->whereKey($session->getKey())->increment($column, 1, $extra);
        $session->setAttribute($column, (int) $session->getAttribute($column) + 1);

        if (null !== $iterationSteps) {
            $session->setAttribute('loop_iteration_step_count', $iterationSteps);
        }

        $session->syncOriginalAttributes([$column, 'loop_iteration_step_count']);
    }

    /**
     * Best-effort failure persistence when a handler throws an unhandled exception.
     *
     * Marks the session as failed and records a FlowFailed analytics event in its
     * own transaction, then writes the failed flow-log entry outside it. Every
     * error here (an optimistic-lock conflict from a concurrent worker, a broken
     * log write) is logged and swallowed so the original handler exception always
     * reaches the queue retry policy unmasked.
     */
    private function markSessionFailed(
        FlowSession $session,
        string $nodeId,
        string $nodeType,
        int $nodeVersion,
        Throwable $handlerException,
    ): bool {
        $persisted = false;

        try {
            $this->connection->transaction(function () use ($session): void {
                $session->saveWithOptimisticLock([
                    'status' => FlowSessionStatus::Failed,
                ]);

                $this->afterCommit(function () use ($session): void {
                    $this->analyticsWriter->record(
                        new AnalyticsEvent(
                            tenantId: (string)$session->tenant_id,
                            eventType: AnalyticsEventType::FlowFailed,
                            payload: ['session_id' => (string)$session->getKey()],
                            occurredAt: new DateTimeImmutable(),
                        )
                    );
                });
            });

            $persisted = true;
        } catch (Throwable $persistException) {
            $this->logger->warning('Failed to persist flow session failure state after handler exception.', [
                'session_id' => (string)$session->getKey(),
                'node_id'    => $nodeId,
                'node_type'  => $nodeType,
                'error'      => $persistException->getMessage(),
            ]);
        }

        $this->writeLogSafely(new FlowLogEntry(
            sessionId: (string)$session->getKey(),
            nodeId: $nodeId,
            nodeType: $nodeType,
            nodeVersion: $nodeVersion,
            status: FlowLogStatus::Failed,
            sourceHandle: null,
            stateChanges: null,
            resolved: null,
            error: ['message' => $handlerException->getMessage()],
        ));

        return $persisted;
    }

    /**
     * Records the node a handler failed at, before the failure is persisted, so the trace keeps it even if the persist
     * fails.
     *
     * @param  array<string, int>  $iterations
     */
    private function traceFailure(
        FlowSession $session,
        string $nodeId,
        string $type,
        int $version,
        Throwable $exception,
        int|float $startedAt,
        array $iterations,
    ): void {
        $this->trace->failed(
            $session,
            $nodeId,
            $type,
            $version,
            $exception::class,
            $exception->getMessage(),
            (hrtime(true) - $startedAt) / 1_000_000,
            $iterations,
        );
    }

    /**
     * Flow logs are observability, not state: a broken log write must never
     * abort execution or trigger a queue retry, which would re-run nodes whose
     * side effects already reached the user.
     */
    private function writeLogSafely(FlowLogEntry $entry): void
    {
        try {
            $this->logWriter->write($entry);
        } catch (Throwable $exception) {
            $this->logger->warning('flow.log_write_failed', [
                'session_id' => $entry->sessionId,
                'node_id'    => $entry->nodeId,
                'node_type'  => $entry->nodeType,
                'error'      => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The result of a node whose Solution cannot run it, or null when the node may run.
     *
     * The registry knows the owner of every registered type; availability is asked on every node, because it
     * is per tenant and this engine lives for one job at most. A dotted type that is not registered at the node's
     * version belongs to a Solution whose package was removed or no longer ships that version (Core types never
     * contain a dot).
     */
    private function solutionGate(string $type, int $version): ?NodeExecutionResult
    {
        $owner = $this->registry->ownerOf($type);

        if (null !== $owner && ! $this->solutionAvailability->isAvailable($owner)) {
            return $this->solutionError('solution_inactive', $owner);
        }

        if (null !== $owner && ! $this->solutionAvailability->isReady($owner)) {
            return $this->solutionError('solution_not_ready', $owner);
        }

        if (str_contains($type, '.') && ! $this->registry->has($type, $version)) {
            return null !== $owner
                ? $this->solutionError('solution_version_missing', $owner)
                : $this->solutionError('solution_not_installed', mb_strstr($type, '.', true));
        }

        return null;
    }

    private function solutionError(string $reason, string $solutionId): NodeExecutionResult
    {
        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'error',
            errorMessage: "{$reason}: {$solutionId}",
        );
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resolveNodeVersion(array $node): int
    {
        $raw = $node['version'] ?? 1;

        if (is_int($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (int)$raw;
        }

        return 1;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function buildLogEntry(
        FlowSession $session,
        array $node,
        NodeExecutionResult $result,
        ?string $nextNodeId,
    ): FlowLogEntry {
        return new FlowLogEntry(
            sessionId: (string)$session->getKey(),
            nodeId: (string)($node['id'] ?? ''),
            nodeType: (string)($node['type'] ?? ''),
            nodeVersion: $this->resolveNodeVersion($node),
            status: match (true) {
                NodeExecutionStatus::Failed === $result->status                           => FlowLogStatus::Failed,
                NodeExecutionStatus::Finished === $result->status                         => FlowLogStatus::Terminal,
                NodeExecutionStatus::Executed === $result->status && null === $nextNodeId => FlowLogStatus::Terminal,
                default                                                                   => FlowLogStatus::Executed,
            },
            sourceHandle: $result->sourceHandle,
            stateChanges: [] !== $result->stateChanges ? $result->stateChanges : null,
            resolved: [] !== $result->logResolved ? $result->logResolved : null,
            error: null !== $result->errorMessage ? ['message' => $result->errorMessage] : null,
        );
    }

    private function resolveAnalyticsEventType(
        NodeExecutionResult $result,
        ?string $nextNodeId,
        ?string $endStatus = null,
    ): ?AnalyticsEventType {
        if (null !== $endStatus) {
            return match (EndStatus::tryFrom($endStatus)) {
                EndStatus::Failed    => AnalyticsEventType::FlowFailed,
                EndStatus::Cancelled => AnalyticsEventType::FlowCancelled,
                default              => AnalyticsEventType::FlowCompleted,
            };
        }

        if (NodeExecutionStatus::Executed === $result->status && null === $nextNodeId) {
            return AnalyticsEventType::FlowCompleted;
        }

        if (NodeExecutionStatus::Finished === $result->status) {
            return AnalyticsEventType::FlowCompleted;
        }

        if (NodeExecutionStatus::Failed === $result->status) {
            return AnalyticsEventType::FlowFailed;
        }

        return null;
    }

    /**
     * Extend the session lock before running the next node.
     *
     * The tick is bound to loop iterations rather than wall-clock time, which
     * is sufficient because the lock TTL only has to cover the gap between two
     * ticks — i.e. the slowest single node — not the whole run. Outbound calls
     * cap out below the TTL (`HttpTransport` defaults to a 10s timeout), plus the egress guard's
     * name lookup, which the HTTP timeout does not cover; the guard refuses a lookup that took
     * longer than 8s, so a call is bounded by the resolver's own time, 8s at most for the check
     * itself, and the 10s request.
     *
     * A false return means the TTL lapsed and another worker claimed the slot.
     * ADR Message Routing & Concurrency Control requires abandoning the run at
     * that point: continuing would race the new owner over session state.
     *
     * Entry points outside the routing pipeline (timeout and delay resumes,
     * event starts, the subflow sweeper) register their handle through
     * `FlowExecutionGuard`; a no-op only when an engine call holds no lock.
     */
    private function refreshSessionLock(): void
    {
        $handle = $this->sessionLock->current();

        if (null === $handle) {
            return;
        }

        if (! $this->lockHeartbeat->extend($handle)) {
            $this->sessionLock->clear();

            throw new SessionLockLostException($handle->key);
        }
    }
}
