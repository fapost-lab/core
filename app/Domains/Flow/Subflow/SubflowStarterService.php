<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Flow\History\HistoryWriterFactory;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowGraphResolver;
use DateInterval;
use Exception;
use FAPost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Builds out the subflow lifecycle when a {@code subflow} node executes.
 *
 *  • Resolves {@code flow_id} to the latest active flow_definition.
 *  • Inserts a child flow_session linked to the parent, inheriting tenant /
 *    contact / assistant identity.
 *  • Pauses the parent (status=paused_subflow), extending its expires_at
 *    when shorter than the child's so the parent does not die before the
 *    child has a chance to resume it.
 *  • Starts the child via the FlowEngine.
 */
final readonly class SubflowStarterService
{
    public function __construct(
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowSessionRepositoryInterface $sessions,
        private FlowEngineInterface $engine,
        private FlowGraphResolver $graphResolver,
        private ConnectionInterface $connection,
        private HistoryWriterFactory $historyWriterFactory,
    ) {
    }

    /**
     * Strict ISO 8601 duration parser. Accepts only the {@code P[…]T[…]} form
     * understood by {@see DateInterval} — string inputs that don't begin with
     * `P` (or aren't a valid duration body) raise an exception so the
     * validator can flag them. Use {@code PT24H}, {@code PT15M}, {@code P3D}, …
     *
     * @throws Exception  When {@see DateInterval} cannot parse the input.
     */
    public static function parseTimeout(string $iso8601): DateInterval
    {
        return new DateInterval($iso8601);
    }

    /**
     * @throws RuntimeException  If the target flow has no active definition.
     */
    public function start(
        FlowSession $parent,
        string $childFlowId,
        DateInterval $timeout,
        string $subflowNodeId,
    ): FlowSession {
        $definition = $this->definitions->findLatestActiveByFlowId($childFlowId);

        if (null === $definition) {
            throw new RuntimeException(
                "Subflow target '{$childFlowId}' has no active flow_definition for tenant.",
            );
        }

        $now             = now();
        $childExpiresAt  = $now->clone()->add($timeout);
        $parentExpiresAt = $parent->expires_at;

        $child = $this->connection->transaction(function () use (
            $parent,
            $definition,
            $subflowNodeId,
            $childExpiresAt,
            $parentExpiresAt,
        ): FlowSession {
            // Pause parent first — if engine starts the child synchronously and the
            // child writes any state, the parent must already be in paused_subflow
            // so routing stays correct.
            $parentPatch = ['status' => FlowSessionStatus::PausedSubflow];

            // Parent expiry extension: child must not outlive the buffer window.
            if (null === $parentExpiresAt || $parentExpiresAt < $childExpiresAt) {
                $parentPatch['expires_at'] = $childExpiresAt->clone()->addHour();
            }

            try {
                $parent->saveWithOptimisticLock($parentPatch);
            } catch (OptimisticLockConflictException $exception) {
                throw FlowConcurrencyException::forSession((string)$parent->getKey(), $exception);
            }

            $entryNode = $this->graphResolver->resolveEntryNode($definition);

            return $this->sessions->create([
                'tenant_id'             => $parent->tenant_id,
                'assistant_id'          => $parent->assistant_id,
                'contact_id'            => $parent->contact_id,
                'flow_definition_id'    => $definition->getKey(),
                'flow_version'          => $definition->version,
                'current_node_id'       => $entryNode,
                'parent_session_id'     => (string)$parent->getKey(),
                'parent_resume_node_id' => $subflowNodeId,
                'state'                 => [],
                'status'                => FlowSessionStatus::Active,
                'version'               => 1,
                'expires_at'            => $childExpiresAt,
            ]);
        });

        // Write SubflowStarted to parent's history before driving the child —
        // logging_enabled is determined by the parent's flow_definition.
        $parentDefinition = $this->definitions->findById((string)$parent->flow_definition_id);
        $this->historyWriterFactory->for($parentDefinition)->record(
            eventType: HistoryEventType::SubflowStarted,
            tenantId: (string)$parent->tenant_id,
            sessionId: (string)$parent->getKey(),
            nodeId: $subflowNodeId,
            metadata: [
                'child_session_id' => (string)$child->getKey(),
                'child_flow_id'    => $childFlowId,
            ],
        );

        // Drive the child to its first wait point so a single inbound message
        // can carry parent → child → child-end → parent-resume in one cycle.
        $this->engine->runSession($child);

        return $child;
    }
}
