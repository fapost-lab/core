<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Exceptions\VariableTypeConflictException;
use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\LoopNodeHandler;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\VariableSchemaEntry;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableType;
use App\Domains\Flow\Subflow\CallGraphRepository;
use App\Domains\Flow\Templates\TemplateExpander;
use App\Domains\Flow\Templates\TemplateUsageRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class PublishFlowService
{
    public function __construct(
        private ValidateFlowService $validator,
        private CallGraphRepository $callGraph,
        private VariableSchemaCollector $schemaCollector,
        private VariableSchemaRegistryInterface $schemaRegistry,
        private TenantEventRepositoryInterface $tenantEvents,
        private EmittedEventCollector $emittedEvents,
        private AuditTrailInterface $audit,
        private TemplateExpander $templates,
        private TemplateUsageRepository $templateUsages,
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

            // Strip builder-only annotation nodes (comment) and any edges touching
            // them — they never reach the published definition or the engine.
            [$nodes, $edges] = AnnotationNodeTypes::strip($nodes, $edges);

            if ($draft->isTemplate()) {
                return $this->publishTemplate($draft, $nodes, $edges);
            }

            return $this->publishFlow($draft, $nodes, $edges);
        });

        // Invalidate cached schema so the next request reads fresh data.
        $this->schemaRegistry->invalidate();

        return $definition;
    }

    /**
     * Publishes a flow from a graph in its draft shape: expands its template references, validates the result and
     * writes the new definition with its indexes. Runs inside the caller's transaction, with the flow's draft locked.
     *
     * @param  array<int, mixed>     $nodes
     * @param  array<int, mixed>     $edges
     * @param  FlowDefinition|null   $source         the active definition being republished, whose metadata the new
     *                                               version keeps; null when the draft is published
     * @param  FlowDefinition|null   $republishedBy  the template definition whose publish republishes this flow
     *
     * @throws FlowValidationException
     */
    private function publishFlow(
        FlowDraft $draft,
        array $nodes,
        array $edges,
        ?FlowDefinition $source = null,
        ?FlowDefinition $republishedBy = null,
    ): FlowDefinition {
        // Replace each template reference with a copy of the template's active
        // definition, before loop_end denormalization: the copy re-issues ids,
        // including loop_end.loop_node_id.
        $expansion = $this->templates->expand($nodes, $edges, (string) $draft->assistant_id);

        if ([] !== $expansion->errors) {
            throw new FlowValidationException($expansion->errors);
        }

        $nodes = $expansion->nodes;
        $edges = $expansion->edges;

        // Denormalize iterator_name from each Loop node into its paired
        // LoopEnd nodes so handlers stay graph-unaware at runtime.
        $nodes = $this->denormalizeLoopEndIteratorNames($nodes);

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

        $definition = $this->createDefinition($draft, $nodes, $edges, FlowKind::Flow, $source, $republishedBy);

        // Reverse-index subflow.flow_id references so the next publish can
        // run cycle / depth checks transactionally. Replaces any rows from
        // the previously-active definition of this flow.
        $this->callGraph->replaceForCallerDefinition(
            callerFlowId: $draft->flow_id,
            callerDefinitionId: (string) $definition->getKey(),
            calleeFlowIds: $this->extractSubflowCallees($nodes),
        );

        // Reverse-index the templates this version carries a copy of, so a
        // template publish knows which flows to republish.
        $this->templateUsages->replaceForHost(
            hostFlowId: $draft->flow_id,
            hostDefinitionId: (string) $definition->getKey(),
            templateFlowIds: $expansion->templateFlowIds,
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

        // The graph itself is never stored in the trail: the action says a version went live. A republish made by a
        // template publish names the template version that caused it.
        $properties = ['version' => $definition->version];

        if (null !== $republishedBy) {
            $properties['republished_by_template_flow_id'] = $republishedBy->flow_id;
            $properties['republished_by_template_version'] = $republishedBy->version;
        }

        $this->audit->record(new AuditRecord(
            action: AuditAction::FlowPublished,
            subject: $draft,
            subjectLabel: (string) $draft->name,
            assistantId: $draft->assistant_id,
            properties: $properties,
        ));

        return $definition;
    }

    /**
     * Publishes a template: checks its own graph, writes its definition and republishes every flow that uses it from
     * that flow's active definition, so unpublished work in a host's draft is never published. The variable schema,
     * the subflow index and the event registry are written by the hosts' republishes, from the expanded nodes: a
     * template never runs on its own. If any host would not pass validation with the new version, nothing is
     * published and the error names every such flow.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $edges
     *
     * @throws FlowValidationException
     */
    private function publishTemplate(FlowDraft $draft, array $nodes, array $edges): FlowDefinition
    {
        $nodes = $this->denormalizeLoopEndIteratorNames($nodes);

        $errors = $this->templates->validateTemplateGraph($nodes, $edges);
        $result = $this->validator->execute(nodes: $nodes, edges: $edges);

        array_push($errors, ...$result->errors);

        if ([] !== $errors) {
            throw new FlowValidationException($errors);
        }

        $definition = $this->createDefinition($draft, $nodes, $edges, FlowKind::Template);

        $this->republishHosts($draft, $definition);

        $this->audit->record(new AuditRecord(
            action: AuditAction::FlowPublished,
            subject: $draft,
            subjectLabel: (string) $draft->name,
            assistantId: $draft->assistant_id,
            properties: ['version' => $definition->version],
        ));

        return $definition;
    }

    /**
     * Republishes, in flow id order, every flow whose active definition carries a copy of the template. Each host's
     * draft row is locked before its active definition is read, the same lock a publish of that host takes, so a
     * host publish committed meanwhile is not lost.
     *
     * @throws FlowValidationException listing every host the new template version breaks
     */
    private function republishHosts(FlowDraft $template, FlowDefinition $templateDefinition): void
    {
        $errors = [];

        foreach ($this->templateUsages->hostsOf($template->flow_id) as $hostFlowId) {
            $host = FlowDraft::query()
                ->where('flow_id', $hostFlowId)
                ->lockForUpdate()
                ->first();

            $active = null === $host ? null : FlowDefinition::query()
                ->where('flow_id', $hostFlowId)
                ->active()
                ->orderByDesc('version')
                ->first();

            if (null === $host || null === $active) {
                $this->templateUsages->forgetHost($hostFlowId);

                continue;
            }

            [$nodes, $edges] = $this->templates->collapse(
                is_array($active->nodes) ? $active->nodes : [],
                is_array($active->edges) ? $active->edges : [],
            );

            try {
                $this->publishFlow($host, $nodes, $edges, source: $active, republishedBy: $templateDefinition);
            } catch (FlowValidationException $exception) {
                foreach ($exception->errors as $error) {
                    $errors[] = new FlowValidationErrorDto(
                        path: "template.hosts.{$hostFlowId}",
                        code: 'template_breaks_flow',
                        message: "Flow \"{$active->name}\" would break: {$error->message}",
                    );
                }
            }
        }

        if ([] !== $errors) {
            throw new FlowValidationException($errors);
        }
    }

    /**
     * Writes the next version of the draft's flow and makes it the active one.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $edges
     */
    private function createDefinition(
        FlowDraft $draft,
        array $nodes,
        array $edges,
        FlowKind $kind,
        ?FlowDefinition $source = null,
        ?FlowDefinition $republishedBy = null,
    ): FlowDefinition {
        $lastVersion = FlowDefinition::query()
            ->where('flow_id', $draft->flow_id)
            ->max('version');

        $newVersion = ($lastVersion ?? 0) + 1;

        FlowDefinition::query()
            ->where('flow_id', $draft->flow_id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $attributes = [
            'tenant_id'       => $draft->tenant_id,
            'flow_id'         => $draft->flow_id,
            'version'         => $newVersion,
            'kind'            => $kind,
            'name'            => $source->name ?? $draft->name,
            'nodes'           => $nodes,
            'edges'           => $edges,
            'is_active'       => true,
            'is_public'       => null !== $source ? (bool) $source->is_public : (bool) $draft->is_public,
            'logging_enabled' => null !== $source ? $source->logging_enabled : (bool) $draft->logging_enabled,
            'published_at'    => now(),
        ];

        if (null !== $source && null !== $source->expression_engine) {
            $attributes['expression_engine'] = $source->expression_engine;
        }

        if (null !== $republishedBy) {
            $attributes['republished_by_template_flow_id'] = $republishedBy->flow_id;
            $attributes['republished_by_template_version'] = $republishedBy->version;
        }

        return FlowDefinition::query()->create($attributes);
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

            $path = $variable->group
                ? "{$variable->storage->value}.{$variable->group}.{$variable->name}"
                : "{$variable->storage->value}.{$variable->name}";

            if ($existing->type === $variable->type) {
                // Same base type — for arrays the element type must also match.
                // A variable holding `photo` elements in one flow cannot be
                // reused as an array of `file` in another (spec §7.2).
                if (VariableType::Array === $variable->type) {
                    $existingItem = $this->arrayItemType($existing->properties ?? []);
                    $newItem      = $this->arrayItemType($variable->properties);

                    if (null !== $existingItem && null !== $newItem && $existingItem !== $newItem) {
                        $errors[] = new FlowValidationErrorDto(
                            path: "variable.{$path}",
                            code: 'variable_properties_conflict',
                            message: "Variable \"{$path}\" is already registered as an array of \"{$existingItem}\" elements; flow \"{$flowName}\" tries to use it as an array of \"{$newItem}\". Match the existing element type or use a different variable name.",
                        );
                    }
                }

                continue;
            }

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
     * Extract the array element type from a variable's schema properties.
     *
     * `max_size` is intentionally ignored: it is not carried in node config
     * (it lives only in the registry, spec §7.3), so it cannot be compared for
     * cross-flow conflicts. Only the element type (`item_type`) is authoritative.
     *
     * @param  array<string, mixed>  $properties
     */
    private function arrayItemType(array $properties): ?string
    {
        $itemType = $properties['item_type'] ?? null;

        return is_string($itemType) && '' !== $itemType ? $itemType : null;
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
                    'properties'          => json_encode($variable->properties ?: new stdClass()),
                    'declared_in_flow_id' => $flowId,
                    'declared_by_node_id' => $nodeId,
                    'updated_at'          => $now,
                ],
                uniqueBy: ['storage', 'group', 'name'],
                update: ['type', 'properties', 'declared_in_flow_id', 'declared_by_node_id', 'updated_at'],
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
     * Copy `iterator_name` from each Loop node into its paired LoopEnd nodes so
     * {@see LoopEndNodeHandler} is definition-unaware at runtime (spec §4.3).
     *
     * `loop_node_id` is already present on LoopEnd nodes (set by the builder);
     * this step only denormalizes the human-readable iterator name.
     *
     * @param  array<int, mixed>  $nodes
     * @return array<int, mixed>
     */
    private function denormalizeLoopEndIteratorNames(array $nodes): array
    {
        // Build a lookup table: loop_node_id → iterator_name.
        $iteratorNames = [];

        foreach ($nodes as $node) {
            if (!is_array($node) || LoopNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }

            $id     = is_string($node['id'] ?? null) ? $node['id'] : null;
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $name   = is_string($config['iterator_name'] ?? null) && '' !== $config['iterator_name']
                ? $config['iterator_name']
                : LoopNodeHandler::DEFAULT_ITERATOR_NAME;

            if (null !== $id) {
                $iteratorNames[$id] = $name;
            }
        }

        if ([] === $iteratorNames) {
            return $nodes;
        }

        foreach ($nodes as &$node) {
            if (!is_array($node) || LoopEndNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }

            $config     = is_array($node['config'] ?? null) ? $node['config'] : [];
            $loopNodeId = is_string($config['loop_node_id'] ?? null) ? $config['loop_node_id'] : null;

            if (null !== $loopNodeId && isset($iteratorNames[$loopNodeId])) {
                $config['iterator_name'] = $iteratorNames[$loopNodeId];
                $node['config']          = $config;
            }
        }

        unset($node);

        return $nodes;
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
