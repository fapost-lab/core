<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Exceptions\VariableTypeConflictException;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\VariableSchemaEntry;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\Subflow\CallGraphRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PublishFlowService
{
    public function __construct(
        private ValidateFlowService $validator,
        private CallGraphRepository $callGraph,
        private VariableSchemaCollector $schemaCollector,
        private VariableSchemaRegistryInterface $schemaRegistry,
        private TenantEventRepositoryInterface $tenantEvents,
        private EmittedEventCollector $emittedEvents,
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
            if (!$result->valid) {
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
                'is_public'       => (bool) $draft->is_public,
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

            // Detect cross-flow type conflicts before committing the schema update.
            $this->assertNoVariableTypeConflicts(
                flowId: (string)$definition->getKey(),
                flowName: (string)$draft->name,
                nodes: $nodes,
            );

            // Update per-tenant variable schema with declarations found in this flow.
            $this->upsertVariableSchema(
                tenantId: (string)$draft->tenant_id,
                flowId: (string)$definition->getKey(),
                nodes: $nodes,
            );

            // Register events emitted by this flow into the tenant-wide registry so
            // event triggers on ANY flow/assistant can subscribe to them — even
            // before the event ever fires at runtime.
            $this->tenantEvents->registerEventNames(
                tenantId: (string)$draft->tenant_id,
                eventNames: $this->emittedEvents->collect($nodes),
            );

            return $definition;
        });

        // Invalidate cached schema so the next request reads fresh data.
        $this->schemaRegistry->invalidate();

        return $definition;
    }

    /**
     * Assert that no variable declared in $nodes conflicts with an existing
     * type declaration owned by a *different* flow definition.
     *
     * Own declarations (same $flowId) are always allowed — a re-publish may
     * legitimately change the type of its own variables. Cross-flow conflicts
     * wrap into FlowValidationException so the caller can surface them uniformly.
     *
     * @param  array<int, mixed>  $nodes
     *
     * @throws FlowValidationException
     */
    private function assertNoVariableTypeConflicts(string $flowId, string $flowName, array $nodes): void
    {
        $declarations = $this->schemaCollector->collect($nodes);
        $errors       = [];

        foreach ($declarations as ['variable' => $variable]) {
            /** @var Variable $variable */
            if (null === $variable->type) {
                continue;
            }

            /** @var VariableSchemaEntry|null $existing */
            $existing = VariableSchemaEntry::query()
                ->where('storage', $variable->storage->value)
                ->where('group', $variable->group)
                ->where('name', $variable->name)
                ->first();

            if (null === $existing) {
                continue;
            }

            if ($existing->declared_in_flow_id === $flowId) {
                continue;
            }

            if ($existing->type === $variable->type) {
                continue;
            }

            $path = $variable->group
                ? "{$variable->storage->value}.{$variable->group}.{$variable->name}"
                : "{$variable->storage->value}.{$variable->name}";

            $errors[] = new FlowValidationErrorDto(
                path: "variable.{$path}",
                code: 'variable_type_conflict',
                message: (new VariableTypeConflictException(
                    path: $path,
                    existingType: $existing->type->value,
                    newType: $variable->type->value,
                    declaringFlowName: $flowName,
                ))->getMessage(),
            );
        }

        if ([] !== $errors) {
            throw new FlowValidationException($errors);
        }
    }

    /**
     * Collect variable declarations from published nodes and upsert them into
     * tenant_variable_schema. Called inside the publish transaction.
     *
     * @param  array<int, mixed>  $nodes
     */
    private function upsertVariableSchema(string $tenantId, string $flowId, array $nodes): void
    {
        $declarations = $this->schemaCollector->collect($nodes);

        $now = Carbon::now();

        foreach ($declarations as ['variable' => $variable, 'node_id' => $nodeId]) {
            /** @var Variable $variable */
            VariableSchemaEntry::query()->upsert(
                values: [
                    'id'                  => (string)Str::ulid()->toRfc4122(),
                    'tenant_id'           => $tenantId,
                    'storage'             => $variable->storage->value,
                    'group'               => $variable->group,
                    'name'                => $variable->name,
                    'type'                => $variable->type?->value ?? 'text',
                    'declared_in_flow_id' => $flowId,
                    'declared_by_node_id' => $nodeId,
                    'updated_at'          => $now,
                ],
                uniqueBy: ['storage', 'group', 'name'],
                update: ['type', 'declared_in_flow_id', 'declared_by_node_id', 'updated_at'],
            );
        }
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
            if (!is_array($node) || SubflowNodeHandler::TYPE !== ($node['type'] ?? null)) {
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
