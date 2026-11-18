<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowConcurrencyException;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\History\HistoryWriterFactory;
use App\Domains\Flow\Models\FlowSession;
use Closure;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Psr\Log\LoggerInterface;

/**
 * Production resumer: walks the parent → success/cancelled/failed handle when
 * a subflow child has reached an end node. Maps the child's {@code end_status}
 * to a parent output handle 1:1 (success → success, cancelled → cancelled,
 * failed → failed). Inconsistencies (parent missing, parent not in
 * paused_subflow) are logged but never throw — the child is already terminal.
 * A lost optimistic lock on the parent is retried once; a second conflict propagates.
 * On `success` it hands back the variables the node's `output_mapping` names,
 * read from the child's final state ({@see SubflowMapping::outputs()}).
 */
final readonly class DefaultSubflowResumer implements SubflowResumerInterface
{
    /**
     * @param  Closure(): FlowEngineInterface  $engineResolver  Lazy resolver to break the
     *         circular dependency FlowEngine → SubflowResumer → FlowEngine.
     */
    public function __construct(
        private Closure $engineResolver,
        private HistoryWriterFactory $historyWriterFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function resumeIfChild(FlowSession $child, string $endStatus): void
    {
        $parentId = $child->parent_session_id;

        if (null === $parentId || '' === $parentId) {
            return; // top-level session, nothing to resume.
        }

        $parent = FlowSession::query()->find($parentId);

        if (!$parent instanceof FlowSession) {
            $this->logger->warning('flow.subflow.resume.parent_missing', [
                'child_id'  => (string)$child->getKey(),
                'parent_id' => $parentId,
            ]);

            return;
        }

        if (FlowSessionStatus::PausedSubflow !== $parent->status) {
            $this->logger->warning('flow.subflow.resume.parent_not_paused', [
                'child_id'      => (string)$child->getKey(),
                'parent_id'     => (string)$parent->getKey(),
                'parent_status' => $parent->status?->value,
            ]);

            // Continue anyway — child finished, parent might have been advanced
            // by another path. Resume call will be a no-op if current_node_id
            // doesn't match a subflow-shaped position.
        }

        $sourceHandle  = $this->handleFor($endStatus);
        $subflowNodeId = (string)($parent->current_node_id ?? '');

        // Record SubflowReturned in the parent's history before advancing it.
        // logging_enabled is determined by the parent's own flow_definition.
        $this->historyWriterFactory->for($parent->flowDefinition)->record(
            eventType: HistoryEventType::SubflowReturned,
            tenantId: (string)$parent->tenant_id,
            sessionId: (string)$parent->getKey(),
            nodeId: $subflowNodeId,
            metadata: [
                'child_session_id' => (string)$child->getKey(),
                'end_status'       => $endStatus,
                'source_handle'    => $sourceHandle,
            ],
        );

        $outputs = SubflowNodeHandler::HANDLE_SUCCESS === $sourceHandle
            ? $this->outputsFor($parent, $child, $subflowNodeId)
            : [];

        $engine = ($this->engineResolver)();

        try {
            $engine->resumeAfterSubflow($parent, $sourceHandle, $outputs);
        } catch (FlowConcurrencyException $conflict) {
            // The child is already terminal and nothing re-runs it, so losing the parent's optimistic lock
            // on the resume save would leave the parent in paused_subflow with no child. Retry once, but only
            // when the parent still waits on the same subflow node: a conflict raised later, inside the
            // parent's resumed run, means it already moved on and applying the handle again would misroute it.
            $parent->refresh();

            if (FlowSessionStatus::PausedSubflow !== $parent->status || $parent->current_node_id !== $subflowNodeId) {
                throw $conflict;
            }

            $this->logger->info('flow.subflow.resume.conflict_retry', [
                'child_id'  => (string)$child->getKey(),
                'parent_id' => (string)$parent->getKey(),
                'error'     => $conflict->getMessage(),
            ]);

            $engine->resumeAfterSubflow($parent, $sourceHandle, $outputs);
        }
    }

    /**
     * The parent's variables the subflow node's `output_mapping` takes from the child. Read from the parent's own
     * definition snapshot, and only while the parent still waits on the node that started this child.
     *
     * @return array<string, mixed>
     */
    private function outputsFor(FlowSession $parent, FlowSession $child, string $subflowNodeId): array
    {
        if ('' === $subflowNodeId || $child->parent_resume_node_id !== $subflowNodeId) {
            return [];
        }

        $nodes = $parent->flowDefinition?->nodes;

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (! is_array($node) || ($node['id'] ?? null) !== $subflowNodeId) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            return SubflowMapping::outputs($config, is_array($child->state) ? $child->state : []);
        }

        return [];
    }

    private function handleFor(string $endStatus): string
    {
        return match (EndStatus::tryFrom($endStatus)) {
            EndStatus::Cancelled => SubflowNodeHandler::HANDLE_CANCELLED,
            EndStatus::Failed    => SubflowNodeHandler::HANDLE_FAILED,
            default              => SubflowNodeHandler::HANDLE_SUCCESS,
        };
    }
}
