<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
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

        ($this->engineResolver)()->resumeAfterSubflow($parent, $sourceHandle);
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
