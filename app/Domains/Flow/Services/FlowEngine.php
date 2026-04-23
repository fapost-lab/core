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
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Exceptions\FlowExecutionLimitExceededException;
use App\Domains\Flow\Exceptions\HandlerNotFoundException;
use App\Domains\Flow\Exceptions\InvalidFlowGraphException;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Flow\Logging\FlowLogEntry;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Logging\FlowLogWriter;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\FlowStateNamespace;
use App\Domains\Flow\State\SystemStateKeys;
use DateTimeImmutable;
use FAPost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use FAPost\Foundation\Analytics\DTO\AnalyticsEvent;
use FAPost\Foundation\Analytics\Enums\AnalyticsEventType;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\NodeExecutionContext as FoundationNodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
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
            FlowStateNamespace::SYSTEM => [
                SystemStateKeys::STARTED_AT_LEAF  => now()->toIso8601String(),
                'flow_definition_id'              => (string) $definition->getKey(),
                'contact_id'                      => (string) $contact->getKey(),
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
            $this->analyticsWriter->record(new AnalyticsEvent(
                tenantId: (string) $session->tenant_id,
                eventType: AnalyticsEventType::FlowStarted,
                payload: ['session_id' => (string) $session->getKey()],
                occurredAt: new DateTimeImmutable(),
            ));
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
    private function executeLoop(
        FlowDefinition $definition,
        FlowSession $session,
        ?IncomingMessage $incoming,
        Contact $contact,
    ): void {
        $maxIterations = (int) $this->config->get("flow.execution.max_iterations", 100);
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
                        throw FlowConcurrencyException::forSession((string) $session->getKey(), $exception);
                    }

                    $this->afterCommit(function () use ($session): void {
                        $this->analyticsWriter->record(new AnalyticsEvent(
                            tenantId: (string) $session->tenant_id,
                            eventType: AnalyticsEventType::FlowFailed,
                            payload: ['session_id' => (string) $session->getKey()],
                            occurredAt: new DateTimeImmutable(),
                        ));
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

            if ( ! is_string($nodeId) || '' === $nodeId) {
                throw InvalidFlowGraphException::missingNode($currentNodeId);
            }

            $type = $node['type'] ?? null;

            if ( ! is_string($type) || '' === $type) {
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

            $handlerContext = new FoundationNodeExecutionContext(
                tenantId: $session->tenant_id,
                contactId: $session->contact_id,
                sessionId: (string) $session->getKey(),
                nodeId: $nodeId,
                idempotencyKey: $idempotencyKey,
                platform: $platform,
                resolvedLanguage: $resolvedLanguage,
                incoming: $incomingStep,
            );

            $result = $handler->execute($node, $session->state ?? [], $handlerContext);
            $result = $this->applySystemStateEffects($result);

            $nextNodeId = null;

            if (NodeExecutionStatus::Executed === $result->status && null !== $result->sourceHandle) {
                $nextNodeId = $this->graphResolver->resolveNextNode($definition, $nodeId, $result->sourceHandle);
            }

            $this->connection->transaction(function () use ($session, $contact, $node, $result, $nextNodeId): void {
                $this->applyEffects($contact, $result);
                $this->persister->persist($session, $result, $nextNodeId);
                $this->logWriter->write($this->buildLogEntry($session, $node, $result, $nextNodeId));

                $analyticsEventType = $this->resolveAnalyticsEventType($result, $nextNodeId);
                if (null !== $analyticsEventType) {
                    $this->afterCommit(function () use ($analyticsEventType, $session): void {
                        $this->analyticsWriter->record(new AnalyticsEvent(
                            tenantId: (string) $session->tenant_id,
                            eventType: $analyticsEventType,
                            payload: ['session_id' => (string) $session->getKey()],
                            occurredAt: new DateTimeImmutable(),
                        ));
                    });
                }
            });

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

    private function resolveAnalyticsEventType(NodeExecutionResult $result, ?string $nextNodeId): ?AnalyticsEventType
    {
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
     * @param  array<string, mixed>  $node
     */
    private function buildLogEntry(
        FlowSession $session,
        array $node,
        NodeExecutionResult $result,
        ?string $nextNodeId,
    ): FlowLogEntry {
        return new FlowLogEntry(
            sessionId: (string) $session->getKey(),
            nodeId: (string) ($node['id'] ?? ''),
            nodeType: (string) ($node['type'] ?? ''),
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

    private function applyEffects(Contact $contact, NodeExecutionResult $result): void
    {
        foreach ($result->effects as $effect) {
            if ( ! is_array($effect)) {
                continue;
            }

            if ('set_contact_attribute' === ($effect['type'] ?? null)) {
                $key = $effect['key'] ?? null;
                if ( ! is_string($key) || '' === $key) {
                    continue;
                }

                $this->contactService->updateAttributes(
                    $contact->getKey(),
                    [$key => $effect['value'] ?? null],
                );

                continue;
            }

            if ('set_contact_language' === ($effect['type'] ?? null)) {
                $value = $effect['value'] ?? null;

                if ( ! is_string($value) || '' === $value) {
                    continue;
                }

                $this->contactService->updateLanguage($contact->getKey(), $value);
            }
        }
    }

    private function applySystemStateEffects(NodeExecutionResult $result): NodeExecutionResult
    {
        $stateChanges = $result->stateChanges;

        foreach ($result->effects as $effect) {
            if ( ! is_array($effect) || 'set_contact_language' !== ($effect['type'] ?? null)) {
                continue;
            }

            $value = $effect['value'] ?? null;

            if (is_string($value) && '' !== $value) {
                $stateChanges[SystemStateKeys::LANGUAGE] = $value;
            }
        }

        if ($stateChanges === $result->stateChanges) {
            return $result;
        }

        return new NodeExecutionResult(
            status: $result->status,
            sourceHandle: $result->sourceHandle,
            stateChanges: $stateChanges,
            logResolved: $result->logResolved,
            effects: $result->effects,
            metadata: $result->metadata,
            errorMessage: $result->errorMessage,
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
            return (int) $raw;
        }

        return 1;
    }

    private function afterCommit(callable $callback): void
    {
        // Flow engine transactions run on the default tenant connection in current runtime,
        // so facade-level afterCommit is coupled to the same transaction lifecycle.
        DB::afterCommit($callback);
    }
}
