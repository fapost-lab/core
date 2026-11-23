<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\FlowWebhookEndpointRepositoryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Exceptions\VariableTypeConflictException;
use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\LoopNodeHandler;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\VariableSchemaEntry;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use App\Domains\Flow\Schedule\ScheduleRegistrySync;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableType;
use App\Domains\Flow\Subflow\CallGraphRepository;
use App\Domains\Flow\Templates\TemplateExpander;
use App\Domains\Flow\Templates\TemplateUsageRepository;
use Closure;
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
        private FlowTriggerSet $triggerSet,
        private FlowTriggerRepositoryInterface $triggerRows,
        private FlowWebhookEndpointRepositoryInterface $webhookEndpoints,
        private ScheduleRegistrySync $scheduleRegistry,
    ) {
    }

    /**
     * @param  (Closure(FlowDraft): void)|null  $authorize  asked with the draft once it is locked, before anything is
     *                                                       written: a check of what the draft changes sees exactly what
     *                                                       is published, never a draft saved after the check. It refuses
     *                                                       by throwing, which rolls the publish back.
     */
    public function execute(string $flowId, ?Closure $authorize = null): FlowDefinition
    {
        /** @var FlowDefinition $definition */
        $definition = DB::transaction(function () use ($flowId, $authorize): FlowDefinition {
            $this->lockAssistantTemplatesBeforeOwnDraft($flowId);

            $draft = FlowDraft::query()
                ->where('flow_id', $flowId)
                ->lockForUpdate()
                ->firstOrFail();

            if (null !== $authorize) {
                $authorize($draft);
            }

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
     * Lock order: template drafts before flow drafts. A flow publish that expands a template reads that template's
     * active version and records the usage row only at commit; a concurrent publish of the template must either wait
     * for that row or be waited for. A shared lock on every template draft of the assistant, in flow id order and
     * taken before the flow's own draft, gives both: the template publish holds FOR UPDATE on its own draft, so the
     * two conflict whichever came first. All templates of the assistant are locked, not only the ones the draft
     * mentions now, because the draft may change between this read and the lock on it.
     *
     * Only the direct publish of a flow locks here. {@see self::republishHosts()} runs inside a template publish that
     * already holds the template and the host, and a shared lock on a second template from there would wait for a
     * publish of that template that waits for this host.
     */
    private function lockAssistantTemplatesBeforeOwnDraft(string $flowId): void
    {
        // The kind of a draft never changes, so reading it without a lock is safe.
        $own = FlowDraft::query()
            ->where('flow_id', $flowId)
            ->first(['assistant_id', 'kind']);

        if (null === $own || $own->isTemplate() || null === $own->assistant_id) {
            return;
        }

        FlowDraft::query()
            ->where('assistant_id', $own->assistant_id)
            ->where('kind', FlowKind::Template->value)
            ->orderBy('flow_id')
            ->sharedLock()
            ->get(['id']);
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
        // The engine this version is checked with and snapshotted with: a republish keeps the one the host's active
        // definition was published with, so a changed but unpublished choice in the host's draft is not published.
        $engineId = $this->engineOf($draft, $source);

        // Replace each template reference with a copy of the template's active
        // definition, before loop_end denormalization: the copy re-issues ids,
        // including loop_end.loop_node_id.
        $expansion = $this->templates->expand($nodes, $edges, (string) $draft->assistant_id, $engineId);

        if ([] !== $expansion->errors) {
            throw new FlowValidationException($expansion->errors);
        }

        $nodes = $expansion->nodes;
        $edges = $expansion->edges;

        // Denormalize iterator_name from each Loop node into its paired
        // LoopEnd nodes so handlers stay graph-unaware at runtime.
        $nodes = $this->denormalizeLoopEndIteratorNames($nodes);

        // The triggers this version makes live: the draft's on a publish, the active definition's on a republish by a
        // template, never the host draft's (that would publish the host's unpublished trigger edits). A list never
        // written (null: a draft untouched since triggers were carried, or a definition published before) leaves the
        // live rows as they are; the definition records them, so the next publish manages them.
        $carried  = null !== $source ? $source->triggers : $draft->triggers;
        $triggers = is_array($carried) ? FlowTriggerSet::normalize($carried) : $this->triggerSet->live($draft->flow_id);

        // Pass flowId so the validator can run subflow call-graph checks
        // (cycle / depth) against the existing edges table.
        $result = $this->validator->execute(
            nodes: $nodes,
            triggers: $triggers,
            flowId: $draft->flow_id,
            edges: $edges,
            engineId: $engineId,
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

        $definition = $this->createDefinition($draft, $nodes, $edges, $triggers, FlowKind::Flow, $engineId, $source, $republishedBy);

        // Every trigger goes live here, never on draft save: rows are synced by id, keeping a kept trigger's run state.
        if (is_array($carried)) {
            $live         = $this->triggerSet->live($draft->flow_id);
            $liveWebhooks = $this->webhookTriggerIds($live);
            $this->triggerRows->syncForFlow($draft, $triggers);
            $this->recordTriggerChanges($draft, $definition, $live, $triggers, $republishedBy);

            // The landlord registry follows the schedules this version keeps, adds, changes or drops, after the commit.
            $this->scheduleRegistry->syncAfterCommit(
                (string) $draft->tenant_id,
                array_values(array_unique([...$this->triggerIdsOfType($live, FlowTriggerType::Schedule), ...$this->triggerIdsOfType($triggers, FlowTriggerType::Schedule)])),
            );

            // A live webhook trigger this version deletes, or turns into another type, loses its URL and secret.
            // An endpoint made for a trigger that never went live is left alone: it may be waiting for this draft.
            $this->webhookEndpoints->deleteForTriggers(array_values(array_diff($liveWebhooks, $this->webhookTriggerIds($triggers))));
        }

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
     * Records each trigger this version adds, removes or changes against the rows that were live before it, by the
     * rule {@see FlowTriggerRepositoryInterface::syncForFlow()} follows: ids lowercased, configs compared ignoring key
     * order. The type, the active flag and the priority are recorded as values; the config only as a marker, never its
     * content (a call's headers, keywords or a webhook's settings are not the log's to copy).
     *
     * @param  list<array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}>  $before
     * @param  list<array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}>  $after
     */
    private function recordTriggerChanges(
        FlowDraft $draft,
        FlowDefinition $definition,
        array $before,
        array $after,
        ?FlowDefinition $republishedBy,
    ): void {
        $live = [];

        foreach ($before as $trigger) {
            $live[mb_strtolower($trigger['id'])] = $trigger;
        }

        foreach ($after as $trigger) {
            $id      = mb_strtolower($trigger['id']);
            $current = $live[$id] ?? null;
            unset($live[$id]);

            if (null === $current) {
                $this->recordTrigger(AuditAction::FlowTriggerAdded, $draft, $definition, $trigger, $republishedBy, AuditChanges::created([
                    'type'      => $trigger['type'],
                    'is_active' => $trigger['is_active'],
                    'priority'  => $trigger['priority'],
                ]));

                continue;
            }

            $changes = AuditChanges::between($current, $trigger, ['type', 'is_active', 'priority']);

            if (! FlowTriggerSet::sameConfig($current['config'], $trigger['config'])) {
                $changes = $changes->merge(AuditChanges::markers(['config']));
            }

            if (! $changes->isEmpty()) {
                $this->recordTrigger(AuditAction::FlowTriggerChanged, $draft, $definition, $trigger, $republishedBy, $changes);
            }
        }

        foreach ($live as $gone) {
            $this->recordTrigger(AuditAction::FlowTriggerRemoved, $draft, $definition, $gone, $republishedBy);
        }
    }

    /**
     * @param  array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}  $trigger
     */
    private function recordTrigger(
        AuditAction $action,
        FlowDraft $draft,
        FlowDefinition $definition,
        array $trigger,
        ?FlowDefinition $republishedBy,
        ?AuditChanges $changes = null,
    ): void {
        $properties = [
            'version'      => $definition->version,
            'trigger_id'   => mb_strtolower($trigger['id']),
            'trigger_type' => $trigger['type'],
        ];

        if (null !== $republishedBy) {
            $properties['republished_by_template_flow_id'] = $republishedBy->flow_id;
            $properties['republished_by_template_version'] = $republishedBy->version;
        }

        $this->audit->record(new AuditRecord(
            action: $action,
            subject: $draft,
            subjectLabel: (string) $draft->name,
            assistantId: $draft->assistant_id,
            changes: $changes,
            properties: $properties,
        ));
    }

    /**
     * @param  list<array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}>  $triggers
     *
     * @return list<string>
     */
    private function webhookTriggerIds(array $triggers): array
    {
        return $this->triggerIdsOfType($triggers, FlowTriggerType::Webhook);
    }

    /**
     * @param  list<array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}>  $triggers
     *
     * @return list<string>
     */
    private function triggerIdsOfType(array $triggers, FlowTriggerType $type): array
    {
        $ids = [];

        foreach ($triggers as $trigger) {
            if ($type->value === $trigger['type']) {
                $ids[] = mb_strtolower($trigger['id']);
            }
        }

        return $ids;
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

        $engineId = $this->engineOf($draft, null);
        $errors   = $this->templates->validateTemplateGraph($nodes, $edges);
        $result   = $this->validator->execute(nodes: $nodes, edges: $edges, engineId: $engineId);

        array_push($errors, ...$result->errors);

        if ([] !== $errors) {
            throw new FlowValidationException($errors);
        }

        // A template never starts on its own: it has no triggers.
        $definition = $this->createDefinition($draft, $nodes, $edges, [], FlowKind::Template, $engineId);

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
     * The expression engine a publish uses: the snapshot of the definition being republished, else the draft's choice.
     */
    private function engineOf(FlowDraft $draft, ?FlowDefinition $source): string
    {
        return (string) (null !== $source ? $source->expression_engine : $draft->expression_engine);
    }

    /**
     * Writes the next version of the draft's flow and makes it the active one. The expression engine is always
     * written, never left to the column default: it is the author's choice, snapshotted.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $edges
     * @param  list<array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}>  $triggers
     */
    private function createDefinition(
        FlowDraft $draft,
        array $nodes,
        array $edges,
        array $triggers,
        FlowKind $kind,
        string $engineId,
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
            'tenant_id'         => $draft->tenant_id,
            'flow_id'           => $draft->flow_id,
            'version'           => $newVersion,
            'kind'              => $kind,
            'name'              => $source->name ?? $draft->name,
            'nodes'             => $nodes,
            'edges'             => $edges,
            'triggers'          => $triggers,
            'is_active'         => true,
            'is_public'         => null !== $source ? (bool) $source->is_public : (bool) $draft->is_public,
            'logging_enabled'   => null !== $source ? $source->logging_enabled : (bool) $draft->logging_enabled,
            'expression_engine' => $engineId,
            'published_at'      => now(),
        ];

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
     * `max_size` is intentionally ignored: the registry keeps one value per variable
     * (spec §7.3), so it is not compared for cross-flow conflicts. Only the element
     * type (`item_type`) is authoritative.
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

        // "Keep last" is set per node but the registry keeps one row per variable: a node that leaves it empty must
        // not reset what another node of this flow sets, so the last declared max_size is applied to every row.
        $maxSizes = [];

        foreach ($declarations as ['variable' => $variable]) {
            if (isset($variable->properties['max_size'])) {
                $maxSizes[$this->variableKey($variable)] = $variable->properties['max_size'];
            }
        }

        foreach ($declarations as ['variable' => $variable, 'node_id' => $nodeId]) {
            /** @var Variable $variable */
            $properties = $variable->properties;

            if (isset($maxSizes[$this->variableKey($variable)])) {
                $properties['max_size'] = $maxSizes[$this->variableKey($variable)];
            }

            VariableSchemaEntry::query()->upsert(
                values: [
                    'id'                  => (string)Str::ulid()->toRfc4122(),
                    'tenant_id'           => $tenantId,
                    'storage'             => $variable->storage->value,
                    'group'               => $variable->group,
                    'name'                => $variable->name,
                    'type'                => $variable->type?->value ?? 'text',
                    'properties'          => json_encode($properties ?: new stdClass()),
                    'declared_in_flow_id' => $flowId,
                    'declared_by_node_id' => $nodeId,
                    'updated_at'          => $now,
                ],
                uniqueBy: ['storage', 'group', 'name'],
                update: ['type', 'properties', 'declared_in_flow_id', 'declared_by_node_id', 'updated_at'],
            );
        }
    }

    private function variableKey(Variable $variable): string
    {
        return $variable->storage->value . '|' . ($variable->group ?? '') . '|' . $variable->name;
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
