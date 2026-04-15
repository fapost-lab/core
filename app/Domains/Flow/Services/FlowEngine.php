<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Exceptions\FlowExecutionLimitExceededException;
use App\Domains\Flow\Exceptions\HandlerNotFoundException;
use App\Domains\Flow\Exceptions\InvalidFlowGraphException;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\FlowStateNamespace;
use App\Domains\Flow\State\SystemStateKeys;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\NodeExecutionContext as FoundationNodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
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
        private CurrentAssistantInterface $currentAssistant,
        private ContactServiceInterface $contactService,
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

        $session = $this->connection->transaction(function () use ($assistant, $baseState, $contact, $definition, $entry): FlowSession {
            $created = $this->sessions->create([
                'tenant_id'          => $contact->tenant_id,
                'assistant_id'       => $assistant->getKey(),
                'contact_id'         => $contact->getKey(),
                'flow_definition_id' => $definition->getKey(),
                'flow_version'       => $definition->version,
                'current_node_id'    => $entry,
                'state'              => $baseState,
                'status'             => FlowSessionStatus::Active,
                'version'            => 1,
            ]);

            $this->logWriter->writeFlowStart($created);

            return $created;
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

                    $this->logWriter->writeFlowEnd($session, 'max_iterations_exceeded');
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

            $platform = $incomingStep?->platform ?? $contact->platform->value;

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
                incoming: $incomingStep,
            );

            $result = $handler->execute($node, $session->state ?? [], $handlerContext);

            $nextNodeId = null;

            if (NodeExecutionStatus::Executed === $result->status && null !== $result->sourceHandle) {
                $nextNodeId = $this->graphResolver->resolveNextNode($definition, $nodeId, $result->sourceHandle);
            }

            $this->connection->transaction(function () use ($session, $contact, $node, $result, $nextNodeId): void {
                $this->applyEffects($contact, $result);
                $this->persister->persist($session, $result, $nextNodeId);
                $this->logWriter->write($session, $node, $result, $nextNodeId);

                $reason = $this->resolveTerminalReason($result, $nextNodeId);

                if (null !== $reason) {
                    $this->logWriter->writeFlowEnd($session, $reason);
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

    /**
     * Lifecycle {@code flow_end} reason for this step, or {@code null} when no terminal lifecycle row is written.
     */
    private function resolveTerminalReason(NodeExecutionResult $result, ?string $nextNodeId): ?string
    {
        if (NodeExecutionStatus::Executed === $result->status && null === $nextNodeId) {
            return 'no_next_node';
        }

        if (NodeExecutionStatus::Finished === $result->status) {
            return 'finished';
        }

        if (NodeExecutionStatus::Failed === $result->status) {
            return 'failed';
        }

        return null;
    }

    private function applyEffects(Contact $contact, NodeExecutionResult $result): void
    {
        foreach ($result->effects as $effect) {
            if ( ! is_array($effect) || 'set_contact_attribute' !== ($effect['type'] ?? null)) {
                continue;
            }

            $key = $effect['key'] ?? null;
            if ( ! is_string($key) || '' === $key) {
                continue;
            }

            $this->contactService->updateAttributes(
                $contact->getKey(),
                [$key => $effect['value'] ?? null],
            );
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
            return (int) $raw;
        }

        return 1;
    }
}
