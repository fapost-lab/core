<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Subflow\CallGraphRepository;
use Illuminate\Support\Facades\DB;

final readonly class PublishFlowService
{
    public function __construct(
        private ValidateFlowService $validator,
        private CallGraphRepository $callGraph,
    ) {
    }

    public function execute(string $flowId): FlowDefinition
    {
        /** @var FlowDefinition $definition */
        $definition = DB::transaction(function () use ($flowId): FlowDefinition {
            $draft = FlowDraft::query()
                ->where('flow_id', $flowId)
                ->lockForUpdate()
                ->firstOrFail();

            $nodes = is_array($draft->nodes) ? $draft->nodes : [];
            $edges = is_array($draft->edges) ? $draft->edges : [];

            // Pass flowId so the validator can run subflow call-graph checks
            // (cycle / depth) against the existing edges table.
            $result = $this->validator->execute(
                nodes: $nodes,
                edges: $edges,
                flowId: $draft->flow_id,
            );
            if ( ! $result->valid) {
                throw new FlowValidationException($result->errors);
            }

            // Cross-assistant subflow rejection: a flow may only invoke other
            // flows owned by the same assistant. Cross-assistant composition
            // is V2 (separate ADR). Resolved by looking up the callee draft's
            // owner — drafts are unique per flow_id and persist past publish.
            $crossAssistantErrors = $this->checkCrossAssistantSubflows($nodes, $draft);
            if ([] !== $crossAssistantErrors) {
                throw new FlowValidationException($crossAssistantErrors);
            }

            $lastVersion = FlowDefinition::query()
                ->where('flow_id', $draft->flow_id)
                ->max('version');

            $newVersion = ($lastVersion ?? 0) + 1;

            FlowDefinition::query()
                ->where('flow_id', $draft->flow_id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $definition = FlowDefinition::query()->create([
                'tenant_id'       => $draft->tenant_id,
                'flow_id'         => $draft->flow_id,
                'version'         => $newVersion,
                'name'            => $draft->name,
                'nodes'           => $nodes,
                'edges'           => $edges,
                'is_active'       => true,
                'logging_enabled' => (bool) $draft->logging_enabled,
                'published_at'    => now(),
            ]);

            // Reverse-index subflow.flow_id references so the next publish can
            // run cycle / depth checks transactionally. Replaces any rows from
            // the previously-active definition of this flow.
            $this->callGraph->replaceForCallerDefinition(
                callerFlowId: $draft->flow_id,
                callerDefinitionId: (string) $definition->getKey(),
                calleeFlowIds: $this->extractSubflowCallees($nodes),
            );

            return $definition;
        });

        return $definition;
    }

    /**
     * Reject subflow.flow_id references that point at flows owned by a
     * different assistant. Looks up each callee in `flow_drafts` (the unique
     * source of truth for assistant ownership in V1). Missing drafts are
     * silently allowed — the {@see CallGraphValidator} already flags broken
     * references via cycle / depth rules; a wholly missing target raises
     * {@see RuntimeException} at runtime anyway through SubflowStarter.
     *
     * @param  array<int, mixed>             $nodes
     * @return list<FlowValidationErrorDto>
     */
    private function checkCrossAssistantSubflows(array $nodes, FlowDraft $caller): array
    {
        $errors      = [];
        $callerOwner = (string) $caller->assistant_id;
        $callees     = $this->extractSubflowCallees($nodes);

        if ('' === $callerOwner || [] === $callees) {
            return [];
        }

        $owners = FlowDraft::query()
            ->whereIn('flow_id', $callees)
            ->pluck('assistant_id', 'flow_id');

        foreach ($callees as $callee) {
            $calleeOwner = (string) ($owners[$callee] ?? '');

            if ('' === $calleeOwner || $calleeOwner === $callerOwner) {
                continue;
            }

            $errors[] = new FlowValidationErrorDto(
                path: 'subflow.cross_assistant',
                code: 'subflow_cross_assistant',
                message: "Subflow '{$callee}' belongs to a different assistant — cross-assistant subflows are not supported in V1.",
            );
        }

        return $errors;
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @return list<string>
     */
    private function extractSubflowCallees(array $nodes): array
    {
        $callees = [];

        foreach ($nodes as $node) {
            if ( ! is_array($node) || SubflowNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $flowId = $config['flow_id'] ?? null;

            if (is_string($flowId) && '' !== $flowId) {
                $callees[] = $flowId;
            }
        }

        return $callees;
    }
}
