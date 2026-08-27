<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\LanguageResolverInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\Enums\EndStatus;
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
use App\Domains\Flow\State\SystemStateKeys;
use DateTimeImmutable;
use Fapost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use Fapost\Foundation\Analytics\DTO\AnalyticsEvent;
use Fapost\Foundation\Analytics\Enums\AnalyticsEventType;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\NodeExecutionContext as FoundationNodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Enums\StateNamespace;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
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
        private Repository $config,
        private \App\Domains\Flow\Expression\ExpressionEngineRegistry $expressionEngines,
        private \App\Domains\Flow\Contracts\DataAccessorRegistryInterface $dataAccessors,
        private \App\Domains\Flow\History\HistoryWriterFactory $historyWriterFactory,
        private \App\Domains\Flow\Subflow\SubflowResumerInterface $subflowResumer,
        private \App\Domains\Messaging\Typing\TypingHeartbeatRegistry $typingHeartbeat,
        private LoggerInterface $logger,
        private VariableSchemaRegistryInterface $schemaRegistry,
        private \App\Domains\Flow\Concurrency\SessionLockRegistry $sessionLock,
        private \App\Domains\Flow\Concurrency\LockHeartbeat $lockHeartbeat,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function start(
        FlowDefinition $definition,
        Contact $contact,
        array $initialState = [],
    ): FlowSession {
        $entry = $this->graphResolver->resolveEntryNode($definition);

        $baseState = array_replace_recursive([
            StateNamespace::System->value => [
                SystemStateKeys::STARTED_AT_LEAF  => now()->toIso8601String(),
                'flow_definition_id'              => (string)$definition->getKey(),
                'contact_id'                      => (string)$contact->getKey(),
                SystemStateKeys::RETRY_COUNT_LEAF => 0,
            ],
        ], $initialState);

        $assistant = $this->currentAssistant->get();

        $session = $this->connection->transaction(fn (): FlowSession => $this->sessions->create([
            'tenant_id'          => $contact->tenant_id,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'current_node_id'    => $entry,
            'state'              => $baseState,
            'status'             => FlowSessionStatus::Active,
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
        $contact    = $this->contactService->findById($session->contact_id);

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

        $baseState = array_replace_recursive([
            StateNamespace::System->value => [
                SystemStateKeys::STARTED_AT_LEAF  => now()->toIso8601String(),
                'flow_definition_id'              => (string)$definition->getKey(),
                'contact_id'                      => (string)$contact->getKey(),
                SystemStateKeys::RETRY_COUNT_LEAF => 0,
            ],
        ], $initialState);

        $assistant = $this->currentAssistant->get();

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

    public function runSession(FlowSession $session): FlowSession
    {
        $session->refresh();

        $definition = $this->definitions->findById($session->flow_definition_id);
        $contact    = $this->contactService->findById($session->contact_id);

        $this->executeLoop($definition, $session, null, $contact);
        $session->refresh();

        return $session;
    }

    public function resumeAfterSubflow(FlowSession $parent, string $sourceHandle): FlowSession
    {
        $parent->refresh();

        $definition = $this->definitions->findById($parent->flow_definition_id);
        $contact    = $this->contactService->findById($parent->contact_id);
        $resumeNode = (string) ($parent->current_node_id ?? '');

        if ('' === $resumeNode) {
            // Parent was already advanced past the subflow node — nothing to do.
            return $parent;
        }

        $nextNodeId = $this->graphResolver->resolveNextNode($definition, $resumeNode, $sourceHandle);

        $this->connection->transaction(function () use ($parent, $nextNodeId): void {
            try {
                $parent->saveWithOptimisticLock([
                    'status'          => null !== $nextNodeId ? FlowSessionStatus::Active : FlowSessionStatus::Completed,
                    'current_node_id' => $nextNodeId,
                ]);
            } catch (OptimisticLockConflictException $exception) {
                throw FlowConcurrencyException::forSession((string)$parent->getKey(), $exception);
            }
        });

        if (null !== $nextNodeId) {
            $this->executeLoop($definition, $parent, null, $contact);
        }

        $parent->refresh();

        return $parent;
    }

    private function afterCommit(callable $callback): void
    {
        // Flow engine transactions run on the default tenant connection in current runtime,
        // so facade-level afterCommit is coupled to the same transaction lifecycle.
        DB::afterCommit($callback);
    }

    /**
     * @throws Throwable
     */
    private function executeLoop(
        FlowDefinition $definition,
        FlowSession $session,
        ?IncomingMessage $incoming,
        Contact $contact,
    ): void {
        $maxIterations = (int)$this->config->get("flow.execution.max_iterations", 100);
        $iterations    = 0;
        $incomingStep  = $incoming;

        while (true) {
            if (++$iterations > $maxIterations) {
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

                throw new FlowExecutionLimitExceededException();
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

            try {
                $handler = $this->registry->resolve($type, $version);
            } catch (LogicException $exception) {
                throw HandlerNotFoundException::forTypeAndVersion($type, $version, $exception);
            }

            $platform         = $incomingStep?->platform ?? $contact->platform->value;
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
            } catch (Throwable $handlerException) {
                $this->markSessionFailed($session, $nodeId, $type, $version, $handlerException);

                throw $handlerException;
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
            // the stale Waiting result here would either lose that progress or
            // raise an OptimisticLockConflict. Skip persistence in that scenario.
            $skipPersist = false;
            if (SubflowNodeHandler::TYPE === $type
                && NodeExecutionStatus::Waiting === $result->status
            ) {
                $latest = FlowSession::query()->find($session->getKey());
                if (null !== $latest && $latest->version !== $session->version) {
                    $skipPersist = true;
                    $session->setRawAttributes($latest->getAttributes(), true);
                }
            }

            $this->connection->transaction(function () use ($session, $node, $result, $nextNodeId, $endStatus, $skipPersist, $type): void {
                if ($skipPersist) {
                    // No-op: state already reflects the most recent write.
                } elseif (null !== $endStatus) {
                    $this->persister->persistEnd($session, $result, $endStatus, $type);
                } else {
                    $this->persister->persist($session, $result, $nextNodeId, $type);
                }

                $this->logWriter->write($this->buildLogEntry($session, $node, $result, $nextNodeId));

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

            if (null !== $endStatus) {
                $this->subflowResumer->resumeIfChild($session, $endStatus);
            }

            $incomingStep = null;

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
     * Best-effort failure persistence when a handler throws an unhandled exception.
     *
     * Marks the session as failed, writes a failed flow-log entry and records a
     * FlowFailed analytics event so the crash is observable. Runs in its own
     * transaction; any persistence error (including an optimistic-lock conflict
     * from a concurrent worker) is logged and swallowed so the original handler
     * exception always reaches the queue retry policy unmasked.
     */
    private function markSessionFailed(
        FlowSession $session,
        string $nodeId,
        string $nodeType,
        int $nodeVersion,
        Throwable $handlerException,
    ): void {
        try {
            $this->connection->transaction(function () use ($session, $nodeId, $nodeType, $nodeVersion, $handlerException): void {
                $session->saveWithOptimisticLock([
                    'status' => FlowSessionStatus::Failed,
                ]);

                $this->logWriter->write(new FlowLogEntry(
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
        } catch (Throwable $persistException) {
            $this->logger->warning('Failed to persist flow session failure state after handler exception.', [
                'session_id' => (string)$session->getKey(),
                'node_id'    => $nodeId,
                'node_type'  => $nodeType,
                'error'      => $persistException->getMessage(),
            ]);
        }
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
     * cap out well below the TTL (`HttpTransport` defaults to a 10s timeout).
     *
     * A false return means the TTL lapsed and another worker claimed the slot.
     * ADR Message Routing & Concurrency Control requires abandoning the run at
     * that point: continuing would race the new owner over session state.
     *
     * No-op when nothing is registered — sweepers and timeout resume jobs that
     * enter the engine outside a routing pipeline hold no handle.
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
