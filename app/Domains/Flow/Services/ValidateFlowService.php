<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\DTOs\FlowValidationResultDto;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Events\CoreEventCatalog;
use App\Domains\Flow\Events\EventTriggerAssistantFilter;
use App\Domains\Flow\Expression\ExpressionEngineRegistry;
use App\Domains\Flow\Handlers\EndNodeHandler;
use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\LoopNodeHandler;
use App\Domains\Flow\Handlers\WebhookResponseNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use App\Domains\Flow\Subflow\CallGraphValidator;
use App\Domains\Flow\Subflow\SubflowPin;
use App\Domains\Flow\Subflow\SubflowStarterService;
use App\Domains\Flow\Validation\ButtonLimitChecker;
use App\Domains\Flow\Validation\ChannelCapabilityChecker;
use App\Domains\Flow\Validation\ContactContextChecker;
use App\Domains\Flow\Validation\FlowGraphStructureValidator;
use App\Domains\Flow\Validation\ListSizeChecker;
use App\Domains\Flow\Validation\LoopConfigChecker;
use App\Domains\Flow\Validation\VariablePathConflictChecker;
use App\Domains\Flow\Webhook\WebhookTriggerConfig;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Exception;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\NodeConfigIssueSeverity;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class ValidateFlowService
{
    /** The loop iteration limit a literal loop count is checked against when no platform settings are given (tests). */
    private const int DEFAULT_LOOP_ITERATIONS = 1000;

    public function __construct(
        private NodeHandlerRegistryInterface $registry,
        private DataAccessorRegistryInterface $dataAccessors,
        private FlowTriggerConfigValidatorInterface $triggerValidator,
        private TenantEventRepositoryInterface $tenantEvents,
        private TenantContextInterface $tenantContext,
        private ?CallGraphValidator $callGraphValidator = null,
        private ?PlatformSettingsReaderInterface $platformSettings = null,
        private ?ChannelCapabilityChecker $capabilityChecker = null,
        private ?ButtonLimitChecker $buttonLimitChecker = null,
        private ?FlowGraphStructureValidator $graphValidator = null,
        private ?ContactContextChecker $contactContext = null,
        private ?LoopConfigChecker $loopConfig = null,
        private ?ListSizeChecker $listSize = null,
        private ?VariablePathConflictChecker $variablePaths = null,
        private ?ExpressionEngineRegistry $expressionEngines = null,
    ) {
    }

    /**
     * @param  array<string, mixed>              $nodes
     * @param  list<mixed>|null                  $triggers  the flow's trigger list as it will go live; null leaves the
     *                                                      triggers unchecked, and the contact check then reads the
     *                                                      flow's live triggers
     * @param  array<int, array<string, mixed>>  $edges
     * @param  string|null                       $engineId  the expression engine the flow is checked with: the draft's
     *                                                      choice, or the snapshot of a republished definition; null
     *                                                      checks with the `template` engine (callers without a flow)
     */
    public function execute(
        array $nodes,
        ?array $triggers = null,
        ?string $flowId = null,
        array $edges = [],
        ?string $assistantId = null,
        ?string $engineId = null,
    ): FlowValidationResultDto {
        $errors   = [];
        $warnings = [];
        $nodeMap  = $this->normalizeNodeMap($nodes, $errors);

        $this->validateExpressionEngine($engineId, $errors);

        foreach ($nodeMap as $nodeId => $node) {
            // Annotation nodes (comment) are builder-only — no handler, no runtime
            // behaviour — so they are exempt from node-level validation.
            if (AnnotationNodeTypes::isAnnotation($node['type'] ?? null)) {
                continue;
            }

            $path = "nodes.{$nodeId}";
            $this->validateRegisteredNodeType($node, $path, $errors);
            $this->validateRequiredConfig($node, $path, $errors);
            $this->validateOutputs($node, $path, $nodeMap, $errors);
            $this->validateBranchNodeConnections($node, $path, $errors);
            $this->validateUnknownModuleReferences($node, $path, $errors);
            $this->validateSendMessageMediaConfig($node, $path, $errors);
            $this->validateEmitEventConfig($node, $path, $errors);
            $this->validateEndNodeConfig($node, $path, $errors);
            $this->validateRagQueryConfig($node, $path, $errors);
            $this->validateSubflowConfig($node, $path, $errors);
            $this->validateInputSaveTarget($node, $path, $errors);
            $this->validateLoopConfig($node, $path, $errors);
            $this->validateLoopEndConfig($node, $path, $nodeMap, $errors);
            $this->validateWithNodeHandler($node, $path, $errors, $warnings);
        }

        $this->warnSolutionNodesWithoutErrorExit($nodeMap, $edges, $warnings);
        $this->warnWebhookResponseWithoutSyncTrigger($nodeMap, $triggers, $warnings);

        $this->validateEndNodeOutgoingEdges($nodeMap, $edges, $errors);
        $this->validateSubflowTargetsAreNotTemplates($nodeMap, $errors);
        $this->validateSubflowPinnedVersionsExist($nodeMap, $errors);
        $this->validateSubflowCallGraph($flowId, $nodeMap, $errors);
        $this->validateNoNestedLoops($nodeMap, $edges, $errors);
        $this->validateLoopReachesLoopEnd($nodeMap, $edges, $errors);

        $this->validateInlineKeyboardIsTerminal($nodeMap, $edges, $errors);
        $this->validateButtonEdgesMatchExistingButtons($nodeMap, $edges, $errors);
        $this->validateButtonValueTypes($nodeMap, $errors);
        $this->validateTriggers($triggers, $errors, $flowId);

        // Annotation nodes are stripped inside, so a draft and an already stripped publish are checked alike.
        // Skipped when the validator was not injected (legacy unit tests that build the service by hand).
        if (null !== $this->graphValidator) {
            array_push($errors, ...$this->graphValidator->validate($nodes, $edges));
        }

        // A flow with a trigger that gives no contact may hold no node that needs one. Without a list, the flow's live
        // triggers decide. Skipped when the checker was not injected (legacy unit tests).
        if (null !== $this->contactContext) {
            array_push($errors, ...$this->contactContext->check($this->withoutAnnotations($nodeMap), $triggers, $flowId, $engineId));
        }

        // Loop mistakes that would otherwise surface at runtime: iterator/variable clash, non-numeric count, a while
        // condition nothing in the body changes (a warning). Skipped when the checker was not injected (legacy unit tests).
        if (null !== $this->loopConfig) {
            $loopFindings = $this->loopConfig->check($this->withoutAnnotations($nodeMap), $edges);
            array_push($errors, ...$loopFindings['errors']);
            array_push($warnings, ...$loopFindings['warnings']);
        }

        // A list variable's max_size must be a whole number in range. Skipped when the checker was not injected.
        if (null !== $this->listSize) {
            array_push($errors, ...$this->listSize->check($this->withoutAnnotations($nodeMap)));
        }

        // One path written as a single value by one node and as a group by another. Skipped when the checker was not injected.
        if (null !== $this->variablePaths) {
            array_push($errors, ...$this->variablePaths->check($this->withoutAnnotations($nodeMap)));
        }

        if (null !== $assistantId) {
            $channelNodes = $this->withoutAnnotations($nodeMap);

            $warnings = [
                ...$warnings,
                ...($this->capabilityChecker?->check($channelNodes, $assistantId) ?? []),
                ...($this->buttonLimitChecker?->check($channelNodes, $assistantId) ?? []),
            ];
        }

        return new FlowValidationResultDto(
            valid: [] === $errors,
            errors: $errors,
            warnings: $warnings,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap
     *
     * @return array<string, array<string, mixed>>
     */
    private function withoutAnnotations(array $nodeMap): array
    {
        return array_filter(
            $nodeMap,
            static fn (array $node): bool => ! AnnotationNodeTypes::isAnnotation($node['type'] ?? null),
        );
    }

    /**
     * A flow is checked and published only with an engine the registry knows: a definition snapshotted with an
     * engine that is gone (a removed Solution or plugin) would fail every session it starts. Skipped when no engine
     * is given or the registry was not injected (legacy unit tests).
     *
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateExpressionEngine(?string $engineId, array &$errors): void
    {
        if (null === $engineId || null === $this->expressionEngines || $this->expressionEngines->has($engineId)) {
            return;
        }

        $errors[] = new FlowValidationErrorDto(
            path: 'expression_engine',
            code: 'expression_engine_unknown',
            message: "The flow's expression engine '{$engineId}' is not installed. Choose another expression engine for the flow.",
        );
    }

    /**
     * @param  list<FlowValidationErrorDto>  $errors
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizeNodeMap(array $nodes, array &$errors): array
    {
        $normalized = [];

        foreach ($nodes as $key => $node) {
            if (! is_array($node)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$key}",
                    code: 'invalid_node',
                    message: 'Node payload must be an object.',
                );
                continue;
            }

            $nodeId = $node['id'] ?? $key;
            if (! is_string($nodeId) || '' === $nodeId) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$key}.id",
                    code: 'invalid_node_id',
                    message: 'Node id is required.',
                );
                continue;
            }

            $normalized[$nodeId] = $node;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateRegisteredNodeType(array $node, string $path, array &$errors): void
    {
        $type    = $node['type'] ?? null;
        $version = $node['version'] ?? 1;

        if (! is_string($type) || '' === $type || ! is_int($version)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.type",
                code: 'invalid_handler_reference',
                message: 'Node type/version is invalid.',
            );

            return;
        }

        if (! $this->registry->has($type, $version)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.type",
                code: 'unknown_node_type',
                message: "Node handler is not registered for {$type}@{$version}.",
            );
        }
    }

    /**
     * A handler that implements {@see NodeConfigValidatorInterface} (Core's subflow node, or a Solution's node) checks its own
     * node after the generic checks above. An error issue blocks publishing like any other error.
     *
     * @param  array<string, mixed>  $node
     * @param  list<FlowValidationErrorDto>  $errors
     * @param  list<FlowValidationErrorDto>  $warnings
     */
    private function validateWithNodeHandler(array $node, string $path, array &$errors, array &$warnings): void
    {
        $type    = $node['type'] ?? null;
        $raw     = $node['version'] ?? 1;
        $version = is_int($raw) ? $raw : (is_numeric($raw) ? (int) $raw : 1);

        if (! is_string($type) || ! $this->registry->has($type, $version)) {
            return;
        }

        $handler = $this->registry->resolve($type, $version);

        if (! $handler instanceof NodeConfigValidatorInterface) {
            return;
        }

        try {
            $issues = $handler->validate($node);
        } catch (Throwable $exception) {
            $errors[] = new FlowValidationErrorDto(
                path: $path,
                code: 'node_validator_failed',
                message: "The validator of {$type} failed: {$exception->getMessage()}",
            );

            return;
        }

        foreach ($issues as $issue) {
            $dto = $this->issueToDto($issue, $path);

            if (NodeConfigIssueSeverity::Warning === $issue->severity) {
                $warnings[] = $dto;

                continue;
            }

            $errors[] = $dto;
        }
    }

    private function issueToDto(NodeConfigIssue $issue, string $path): FlowValidationErrorDto
    {
        return new FlowValidationErrorDto(
            path: null !== $issue->field && '' !== $issue->field ? "{$path}.config.{$issue->field}" : $path,
            code: $issue->code,
            message: $issue->message,
        );
    }

    /**
     * Every node of a Solution is expected to declare an `error` exit; a Solution that is switched off, or a
     * failing call, leaves the run there. Without an edge the run simply ends, so the author is told.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<int, array<string, mixed>>     $edges
     * @param  list<FlowValidationErrorDto>         $warnings
     */
    private function warnSolutionNodesWithoutErrorExit(array $nodeMap, array $edges, array &$warnings): void
    {
        $connected = [];

        foreach ($edges as $edge) {
            if (is_array($edge) && is_string($edge['from'] ?? null) && 'error' === ($edge['handle'] ?? null)) {
                $connected[$edge['from']] = true;
            }
        }

        foreach ($nodeMap as $nodeId => $node) {
            $type = $node['type'] ?? null;

            if (! is_string($type) || null === $this->registry->ownerOf($type) || isset($connected[$nodeId])) {
                continue;
            }

            $warnings[] = new FlowValidationErrorDto(
                path: "nodes.{$nodeId}",
                code: 'solution_node_error_unhandled',
                message: "Node {$nodeId} ({$type}) has no connection on its error exit: if its Solution is switched off for the tenant or the node fails, the flow ends there.",
            );
        }
    }

    /**
     * A `webhook_response` node answers only a sender that waits, which only a webhook trigger in `sync` mode makes.
     * Without one the node does nothing — unless the flow runs as a subflow of a flow that has one — so the author is
     * told. Skipped when the triggers are not given.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  list<mixed>|null                     $triggers
     * @param  list<FlowValidationErrorDto>         $warnings
     */
    private function warnWebhookResponseWithoutSyncTrigger(array $nodeMap, ?array $triggers, array &$warnings): void
    {
        if (null === $triggers) {
            return;
        }

        foreach ($triggers as $trigger) {
            $config = is_array($trigger) && is_array($trigger['config'] ?? null) ? $trigger['config'] : [];

            if (is_array($trigger)
                && FlowTriggerType::Webhook->value === ($trigger['type'] ?? null)
                && WebhookTriggerConfig::RESPONSE_SYNC === ($config['response_mode'] ?? null)) {
                return;
            }
        }

        foreach ($nodeMap as $nodeId => $node) {
            if (WebhookResponseNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }

            $warnings[] = new FlowValidationErrorDto(
                path: "nodes.{$nodeId}",
                code: 'webhook_response_without_sync_trigger',
                message: "Node {$nodeId} answers a webhook sender, but the flow has no webhook trigger that waits for the answer (response mode sync): unless the flow runs as a subflow of one that has, the node does nothing.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateRequiredConfig(array $node, string $path, array &$errors): void
    {
        $type    = $node['type'] ?? null;
        $version = $node['version'] ?? null;
        $config  = $node['config'] ?? null;
        if (! is_string($type) || ! is_int($version)) {
            return;
        }

        if (! is_array($config)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config",
                code: 'invalid_config',
                message: 'Node config must be an object.',
            );

            return;
        }

        if (! $this->registry->has($type, $version)) {
            return;
        }

        $handler = $this->registry->resolve($type, $version);

        $requiredFields = [];
        $schema         = $handler->configSchema();
        if (is_array($schema)) {
            /** @var mixed $required */
            $required = $schema['required'] ?? [];
            if (is_array($required)) {
                foreach ($required as $field) {
                    if (is_string($field) && '' !== $field) {
                        $requiredFields[] = $field;
                    }
                }
            }
        }

        foreach ($requiredFields as $requiredField) {
            if (! array_key_exists($requiredField, $config)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.config.{$requiredField}",
                    code: 'missing_config_field',
                    message: "Required config field '{$requiredField}' is missing.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>          $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateOutputs(array $node, string $path, array $nodeMap, array &$errors): void
    {
        $outputs = $node['outputs'] ?? null;
        if (! is_array($outputs)) {
            return;
        }

        foreach ($outputs as $handle => $output) {
            if (! is_array($output)) {
                continue;
            }

            $target = $output['next'] ?? $output['target'] ?? null;
            if (! is_string($target) || '' === $target || ! isset($nodeMap[$target])) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.outputs.{$handle}",
                    code: 'unknown_output_target',
                    message: 'Output must reference an existing node id.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateBranchNodeConnections(array $node, string $path, array &$errors): void
    {
        if (! in_array($node['type'] ?? null, ['branch', 'condition'], true)) {
            return;
        }

        // Current model: a branch routes through one or more rules (each with its
        // own handle) plus an implicit `default` (Otherwise) exit. The legacy
        // true/false output pair no longer applies.
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $rules  = is_array($config['rules'] ?? null) ? $config['rules'] : [];

        if ([] === $rules) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.rules",
                code: 'branch_rules_missing',
                message: 'Branch nodes must define at least one condition.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateUnknownModuleReferences(array $node, string $path, array &$errors): void
    {
        $config = $node['config'] ?? null;
        if (! is_array($config)) {
            return;
        }

        array_walk_recursive($config, function (mixed $value) use (&$errors, $path): void {
            if (! is_string($value) || ! str_starts_with($value, 'module.')) {
                return;
            }

            $segments = explode('.', $value, 3);
            if (count($segments) < 3 || '' === $segments[1]) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.config",
                    code: 'invalid_module_reference',
                    message: "Invalid module reference '{$value}'.",
                );

                return;
            }

            $namespace = "{$segments[0]}.{$segments[1]}";
            if (! $this->dataAccessors->has($namespace)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.config",
                    code: 'unknown_module_accessor',
                    message: "Unknown module accessor '{$namespace}'.",
                );
            }
        });
    }

    /**
     * Checks send_message nodes for legacy media fields and validates that any media_file_id
     * references a non-deleted file in the media library.
     *
     * @param  array<string, mixed>  $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateSendMessageMediaConfig(array $node, string $path, array &$errors): void
    {
        if (($node['type'] ?? null) !== 'send_message') {
            return;
        }

        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        if (array_key_exists('media_url', $config) || array_key_exists('media_path', $config)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config",
                code: 'legacy_media_field',
                message: 'This node uses a legacy media_url/media_path field. Please re-attach the file using the media library (media_file_id).',
            );

            return;
        }

        $rawContentType = $config['content_type'] ?? null;
        $contentType    = is_string($rawContentType) ? SendMessageContentType::tryFrom($rawContentType) : null;

        if (! $contentType instanceof SendMessageContentType || ! $contentType->requiresMediaUrl()) {
            return;
        }

        $mediaFileId = $config['media_file_id'] ?? null;

        if (! is_string($mediaFileId) || '' === $mediaFileId) {
            return;
        }

        if (! $this->tenantContext->isResolved()) {
            return;
        }

        if (! MediaFile::query()->where('id', $mediaFileId)->exists()) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.media_file_id",
                code: 'media_file_not_found',
                message: 'The referenced media file does not exist or has been deleted. Please re-attach the file.',
            );
        }
    }

    /**
     * Enforces emit_event constraints from spec §07: event_type is a non-empty
     * string, literal (no template placeholders), and matches the recommended
     * naming convention (alphanumeric segments + dot), outside Core's reserved
     * `core.` namespace ({@see CoreEventCatalog}). The full validity of
     * the resolved payload is the handler's runtime responsibility.
     *
     * @param  array<string, mixed>  $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateEmitEventConfig(array $node, string $path, array &$errors): void
    {
        if (($node['type'] ?? null) !== 'emit_event') {
            return;
        }

        $config    = is_array($node['config'] ?? null) ? $node['config'] : [];
        $eventType = $config['event_type'] ?? null;

        if (! is_string($eventType) || '' === mb_trim($eventType)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.event_type",
                code: 'emit_event_missing_type',
                message: 'emit_event requires a non-empty event_type.',
            );

            return;
        }

        if (str_contains($eventType, '{{')) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.event_type",
                code: 'emit_event_template_in_type',
                message: 'event_type must be a literal value, not a template expression.',
            );

            return;
        }

        if (1 !== preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*$/', $eventType)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.event_type",
                code: 'emit_event_invalid_type',
                message: 'event_type must contain only alphanumerics, underscores and dots (e.g. "sales.order.created").',
            );

            return;
        }

        if (CoreEventCatalog::isReserved($eventType)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.event_type",
                code: 'reserved_event_name',
                message: sprintf('Event names starting with "%s" belong to Core; a flow cannot emit them.', CoreEventCatalog::RESERVED_PREFIX),
            );
        }
    }

    /**
     * Enforces end-node config: status must be one of success / cancelled /
     * failed (literal). Default applies if omitted at runtime, but a save-time
     * value, if present, must match the allowed set.
     *
     * @param  array<string, mixed>  $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateEndNodeConfig(array $node, string $path, array &$errors): void
    {
        if (($node['type'] ?? null) !== EndNodeHandler::TYPE) {
            return;
        }

        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $status = $config['status'] ?? null;

        if (null === $status) {
            return; // handler applies default at runtime
        }

        $allowed = array_column(EndStatus::cases(), 'value');

        if (! is_string($status) || null === EndStatus::tryFrom($status)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.status",
                code: 'end_invalid_status',
                message: 'end.status must be one of: ' . implode(', ', $allowed) . '.',
            );
        }
    }

    /**
     * Structural checks for the rag_query node config: knowledge_base_id is a
     * non-empty literal, provider is a non-empty literal, query is present
     * (template placeholders allowed). Existence of the KB row and validity
     * of provider-specific options is the runtime registry's responsibility.
     *
     * @param  array<string, mixed>          $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateRagQueryConfig(array $node, string $path, array &$errors): void
    {
        if (($node['type'] ?? null) !== \App\Domains\Flow\Handlers\RagQueryNodeHandler::TYPE) {
            return;
        }

        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        $kb = $config['knowledge_base_id'] ?? null;
        if (! is_string($kb) || '' === mb_trim($kb)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.knowledge_base_id",
                code: 'rag_missing_knowledge_base_id',
                message: 'rag_query requires a non-empty knowledge_base_id.',
            );
        } elseif (str_contains($kb, '{{')) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.knowledge_base_id",
                code: 'rag_template_in_knowledge_base_id',
                message: 'knowledge_base_id must be a literal value, not a template expression.',
            );
        }

        $provider = $config['provider'] ?? null;
        if (! is_string($provider) || '' === mb_trim($provider)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.provider",
                code: 'rag_missing_provider',
                message: 'rag_query requires a non-empty provider id.',
            );
        }

        $query = $config['query'] ?? null;
        if (! is_string($query) || '' === mb_trim($query)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.query",
                code: 'rag_missing_query',
                message: 'rag_query requires a non-empty query template.',
            );
        }
    }

    /**
     * subflow node config: flow_id is a non-empty literal, timeout (when
     * specified) parses as ISO 8601 duration. Cycle / depth / cross-assistant
     * checks are aggregated separately in {@see validateSubflowCallGraph()}.
     *
     * @param  array<string, mixed>          $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    /**
     * Input nodes must store the captured value somewhere — accept either
     * the new {@code variable: { name, storage, ... }} shape or the legacy
     * {@code save_to: 'flow.foo'} string. An empty `variable.name` counts
     * as missing (mirrors the inline UI badge) so the author sees the
     * same error in the Validate panel as next to the field.
     *
     * @param  array<string, mixed>          $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateInputSaveTarget(array $node, string $path, array &$errors): void
    {
        if (($node['type'] ?? null) !== 'input') {
            return;
        }

        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        $legacy = $config['save_to'] ?? null;
        if (is_string($legacy) && '' !== mb_trim($legacy)) {
            return;
        }

        $variable = $config['variable'] ?? null;
        if (is_array($variable)) {
            $name = $variable['name'] ?? null;
            if (is_string($name) && '' !== mb_trim($name)) {
                return;
            }
        }

        $errors[] = new FlowValidationErrorDto(
            path: "{$path}.config.variable.name",
            code: 'input_missing_save_target',
            message: 'input requires a variable name to save the captured value into.',
        );
    }

    private function validateSubflowConfig(array $node, string $path, array &$errors): void
    {
        if (($node['type'] ?? null) !== \App\Domains\Flow\Handlers\SubflowNodeHandler::TYPE) {
            return;
        }

        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        $flowId = $config['flow_id'] ?? null;
        if (! is_string($flowId) || '' === mb_trim($flowId)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.flow_id",
                code: 'subflow_missing_flow_id',
                message: 'subflow requires a non-empty flow_id.',
            );
        } elseif (str_contains($flowId, '{{')) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.flow_id",
                code: 'subflow_template_in_flow_id',
                message: 'flow_id must be a literal value, not a template expression.',
            );
        }

        if (false === SubflowPin::read($config)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.flow_version",
                code: 'subflow_invalid_flow_version',
                message: 'flow_version must be a positive whole number (a published version of the target flow) or empty.',
            );
        }

        $timeout = $config['timeout'] ?? null;
        if (null !== $timeout) {
            if (! is_string($timeout) || '' === mb_trim($timeout)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.config.timeout",
                    code: 'subflow_invalid_timeout',
                    message: 'timeout must be a non-empty ISO 8601 duration string (e.g. PT24H).',
                );
            } else {
                try {
                    SubflowStarterService::parseTimeout($timeout);
                } catch (Exception) {
                    $errors[] = new FlowValidationErrorDto(
                        path: "{$path}.config.timeout",
                        code: 'subflow_invalid_timeout',
                        message: "timeout '{$timeout}' is not a valid ISO 8601 duration.",
                    );
                }
            }
        }
    }

    /**
     * A template is never started on its own, so a subflow that calls one fails when it runs. The callees are looked
     * up in one query; a flow id with no draft is left to the call-graph and runtime checks. Skipped, like the
     * call-graph check, when that validator was not injected (legacy unit tests without a database).
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function validateSubflowTargetsAreNotTemplates(array $nodeMap, array &$errors): void
    {
        if (null === $this->callGraphValidator) {
            return;
        }

        $targets = [];

        foreach ($nodeMap as $nodeId => $node) {
            if (\App\Domains\Flow\Handlers\SubflowNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $target = $config['flow_id'] ?? null;

            if (is_string($target) && '' !== mb_trim($target) && ! str_contains($target, '{{')) {
                $targets[(string) $nodeId] = mb_trim($target);
            }
        }

        if ([] === $targets) {
            return;
        }

        $templates = FlowDraft::query()
            ->whereIn('flow_id', array_values(array_unique($targets)))
            ->where('kind', FlowKind::Template->value)
            ->pluck('flow_id')
            ->all();

        foreach ($targets as $nodeId => $target) {
            if (in_array($target, $templates, true)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$nodeId}.config.flow_id",
                    code: 'subflow_target_is_template',
                    message: "Subflow target '{$target}' is a template: templates are placed with a template node, not called as a subflow.",
                );
            }
        }
    }

    /**
     * A node pinned to a version the target flow never published fails when it runs. Versions are looked up in one
     * query, as runnable (flow, not template) definitions of the target flow id, so a version number of another
     * flow never satisfies the pin. A target with no draft or a malformed pin is left to the other checks. Skipped,
     * like the call-graph check, when that validator was not injected (legacy unit tests without a database).
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function validateSubflowPinnedVersionsExist(array $nodeMap, array &$errors): void
    {
        if (null === $this->callGraphValidator) {
            return;
        }

        $pins = [];

        foreach ($nodeMap as $nodeId => $node) {
            if (\App\Domains\Flow\Handlers\SubflowNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }

            $config  = is_array($node['config'] ?? null) ? $node['config'] : [];
            $target  = $config['flow_id'] ?? null;
            $version = SubflowPin::read($config);

            if (is_int($version) && is_string($target) && '' !== mb_trim($target) && ! str_contains($target, '{{')) {
                $pins[(string) $nodeId] = [mb_trim($target), $version];
            }
        }

        if ([] === $pins) {
            return;
        }

        $published = [];

        FlowDefinition::query()
            ->whereIn('flow_id', array_values(array_unique(array_column($pins, 0))))
            ->runnable()
            ->get(['flow_id', 'version'])
            ->each(static function (FlowDefinition $definition) use (&$published): void {
                $published[$definition->flow_id][$definition->version] = true;
            });

        foreach ($pins as $nodeId => [$target, $version]) {
            if (! isset($published[$target][$version])) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$nodeId}.config.flow_version",
                    code: 'subflow_pinned_version_missing',
                    message: "Subflow target '{$target}' has no published version {$version}.",
                );
            }
        }
    }

    /**
     * Aggregates direct subflow callees and runs them through the
     * {@see CallGraphValidator}. Skipped when the validator wasn't injected
     * (legacy unit tests) or no flow_id is known yet (draft path).
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function validateSubflowCallGraph(?string $flowId, array $nodeMap, array &$errors): void
    {
        if (null === $this->callGraphValidator || null === $flowId || '' === $flowId) {
            return;
        }

        $callees = [];
        foreach ($nodeMap as $node) {
            if (\App\Domains\Flow\Handlers\SubflowNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $cid    = $config['flow_id'] ?? null;
            if (is_string($cid) && '' !== mb_trim($cid)) {
                $callees[] = $cid;
            }
        }

        if ([] === $callees) {
            return;
        }

        foreach ($this->callGraphValidator->validate($flowId, $callees) as $violation) {
            $errors[] = new FlowValidationErrorDto(
                path: 'subflow.call_graph',
                code: $violation->code,
                message: $violation->message,
            );
        }
    }

    /**
     * end nodes are terminal — they MUST NOT have outgoing edges. Catches the
     * accidental connection in the editor that would otherwise be silently
     * dropped at runtime (no source handle on Finished status).
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<int, array<string, mixed>>     $edges
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function validateEndNodeOutgoingEdges(array $nodeMap, array $edges, array &$errors): void
    {
        foreach ($edges as $edge) {
            if (! is_array($edge) || ! isset($edge['from']) || ! is_string($edge['from'])) {
                continue;
            }

            $sourceId   = $edge['from'];
            $sourceNode = $nodeMap[$sourceId] ?? null;

            if (null === $sourceNode || EndNodeHandler::TYPE !== ($sourceNode['type'] ?? null)) {
                continue;
            }

            $errors[] = new FlowValidationErrorDto(
                path: "nodes.{$sourceId}",
                code: 'end_node_has_outgoing_edge',
                message: 'end node must be terminal — outgoing edges are not allowed.',
            );
        }
    }

    /**
     * A send_message node with an inline keyboard waits for a button press — no node may follow
     * it via the default handle, as execution would never reach it through normal flow.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<int, array<string, mixed>>     $edges
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function validateInlineKeyboardIsTerminal(array $nodeMap, array $edges, array &$errors): void
    {
        // Build set of nodes that have at least one incoming edge.
        $nodesWithIncoming = [];
        foreach ($edges as $edge) {
            if (is_array($edge) && isset($edge['to'])) {
                $nodesWithIncoming[$edge['to']] = true;
            }
        }

        // Ordered list of root node IDs (preserving the sequence order from the frontend).
        $rootOrder = [];
        foreach (array_keys($nodeMap) as $nodeId) {
            if (! isset($nodesWithIncoming[$nodeId])) {
                $rootOrder[] = $nodeId;
            }
        }

        foreach ($nodeMap as $nodeId => $node) {
            if (($node['type'] ?? null) !== 'send_message') {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            if (($config['content_type'] ?? null) !== 'text_with_keyboard') {
                continue;
            }

            // Case 1: explicit default edge leads out of this node (any branch, any keyboard mode).
            foreach ($edges as $edge) {
                if (! is_array($edge)) {
                    continue;
                }

                if (($edge['from'] ?? null) === $nodeId && ($edge['handle'] ?? 'default') === 'default') {
                    $errors[] = new FlowValidationErrorDto(
                        path: "nodes.{$nodeId}",
                        code: 'keyboard_node_must_be_terminal',
                        message: 'A send_message node with a keyboard cannot have a successor on the default output. It must be the last node in its branch.',
                    );
                    break;
                }
            }

            // Case 2: this node is a root and other root nodes appear after it in the sequence.
            // Those trailing roots are visually displayed after this node but are unreachable at runtime.
            if (! isset($nodesWithIncoming[$nodeId])) {
                $position = array_search($nodeId, $rootOrder, true);
                if (false !== $position && $position < count($rootOrder) - 1) {
                    $errors[] = new FlowValidationErrorDto(
                        path: "nodes.{$nodeId}",
                        code: 'keyboard_node_must_be_terminal',
                        message: 'A send_message node with a keyboard must be the last node in the sequence. Nodes placed after it are unreachable at runtime.',
                    );
                }
            }
        }
    }

    /**
     * Verifies that every non-default edge originating from a send_message node references
     * a button id that actually exists in that node's buttons array. Orphaned handles arise
     * when a button is deleted without removing the corresponding outgoing edge.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<int, array<string, mixed>>     $edges
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function validateButtonEdgesMatchExistingButtons(array $nodeMap, array $edges, array &$errors): void
    {
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $fromId = $edge['from'] ?? null;
            $handle = $edge['handle'] ?? 'default';

            if (! is_string($fromId) || 'default' === $handle) {
                continue;
            }

            $node = $nodeMap[$fromId] ?? null;
            if (null === $node || ($node['type'] ?? null) !== 'send_message') {
                continue;
            }

            $config  = is_array($node['config'] ?? null) ? $node['config'] : [];
            $buttons = is_array($config['buttons'] ?? null) ? $config['buttons'] : [];

            $buttonIds = array_column($buttons, 'id');
            if (! in_array($handle, $buttonIds, true)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$fromId}",
                    code: 'orphaned_button_edge',
                    message: "Edge handle '{$handle}' does not match any button id on this node.",
                );
            }
        }
    }

    /**
     * Validates that each button's value conforms to the declared save_to_type.
     * Only applies to inline keyboard nodes — reply keyboards carry no per-button values.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function validateButtonValueTypes(array $nodeMap, array &$errors): void
    {
        foreach ($nodeMap as $nodeId => $node) {
            if (($node['type'] ?? null) !== 'send_message') {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            if (($config['content_type'] ?? null) !== 'text_with_keyboard') {
                continue;
            }

            if (($config['keyboard_mode'] ?? 'inline') === 'reply') {
                continue;
            }

            $saveToType = $config['save_to_type'] ?? 'string';
            if (! in_array($saveToType, ['string', 'number', 'boolean'], true) || 'string' === $saveToType) {
                continue;
            }

            $buttons = is_array($config['buttons'] ?? null) ? $config['buttons'] : [];

            foreach ($buttons as $idx => $button) {
                $value = $button['value'] ?? '';
                if (! is_string($value) || '' === $value) {
                    continue;
                }

                $valid = match ($saveToType) {
                    'number'  => is_numeric($value),
                    'boolean' => 'true' === $value || 'false' === $value,
                    default   => true,
                };

                if (! $valid) {
                    $errors[] = new FlowValidationErrorDto(
                        path: "nodes.{$nodeId}.config.buttons.{$idx}.value",
                        code: 'button_value_type_mismatch',
                        message: "Button value '{$value}' is not a valid {$saveToType}.",
                    );
                }
            }
        }
    }

    /**
     * Checks every trigger of the list, its id — a UUID, once in the list, not a live trigger of another flow, since
     * it becomes the primary key of the trigger's `flow_triggers` row — and that no two event triggers of the flow
     * listen to the same event (the flow would start twice for one event). Paths are `triggers.<id>.…`, or
     * `triggers.<index>` for an entry without an id.
     *
     * @param  list<mixed>|null              $triggers
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateTriggers(?array $triggers, array &$errors, ?string $flowId): void
    {
        if (null === $triggers) {
            return;
        }

        /** @var array<string, string> $idPaths lowercased id => path */
        $idPaths    = [];
        $eventPaths = [];

        foreach (array_values($triggers) as $index => $trigger) {
            $rawId = is_array($trigger) ? ($trigger['id'] ?? null) : null;
            $path  = 'triggers.' . (is_string($rawId) && '' !== $rawId ? $rawId : (string) $index);

            if (! is_array($trigger)) {
                $errors[] = new FlowValidationErrorDto(
                    path: $path,
                    code: 'invalid_trigger',
                    message: 'Trigger must be an object.',
                );

                continue;
            }

            if (! is_string($rawId) || ! Str::isUuid($rawId)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.id",
                    code: 'invalid_trigger_id',
                    message: 'Trigger id must be a UUID.',
                );
            } elseif (isset($idPaths[mb_strtolower($rawId)])) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.id",
                    code: 'duplicate_trigger_id',
                    message: 'Two triggers of the flow have the same id.',
                );
            } else {
                $idPaths[mb_strtolower($rawId)] = $path;
            }

            $this->validateTrigger($trigger, $path, $errors, $flowId);

            $eventName = FlowTriggerType::Event->value === ($trigger['type'] ?? null) && is_array($trigger['config'] ?? null)
                ? ($trigger['config']['event_name'] ?? null)
                : null;

            if (is_string($eventName) && '' !== mb_trim($eventName)) {
                if (isset($eventPaths[$eventName])) {
                    $errors[] = new FlowValidationErrorDto(
                        path: "{$path}.config.event_name",
                        code: 'duplicate_event_trigger',
                        message: "Another trigger of the flow already starts it on the event '{$eventName}'.",
                    );
                } else {
                    $eventPaths[$eventName] = $path;
                }
            }
        }

        if (null === $flowId || [] === $idPaths) {
            return;
        }

        $taken = FlowTrigger::query()
            ->whereIn('id', array_keys($idPaths))
            ->where('flow_id', '!=', $flowId)
            ->pluck('id')
            ->all();

        foreach ($taken as $id) {
            $errors[] = new FlowValidationErrorDto(
                path: ($idPaths[mb_strtolower((string) $id)] ?? 'triggers') . '.id',
                code: 'trigger_id_taken',
                message: 'Another flow already has a trigger with this id. Remove the trigger and add it again.',
            );
        }
    }

    /**
     * @param  array<array-key, mixed>       $trigger
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateTrigger(array $trigger, string $path, array &$errors, ?string $flowId): void
    {
        $type = $trigger['type'] ?? null;
        if (! is_string($type) || '' === $type) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.type",
                code: 'invalid_trigger_type',
                message: 'Trigger type is required.',
            );

            return;
        }

        if (! is_bool($trigger['is_active'] ?? null)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.is_active",
                code: 'invalid_trigger_active_flag',
                message: 'Trigger active flag must be boolean.',
            );
        }

        $priority = $trigger['priority'] ?? null;
        if (! is_int($priority) || $priority < 0) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.priority",
                code: 'invalid_trigger_priority',
                message: 'Trigger priority must be a non-negative integer.',
            );
        }

        $config = $trigger['config'] ?? null;
        if (! is_array($config)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config",
                code: 'invalid_trigger_config',
                message: 'Trigger config must be an object.',
            );

            return;
        }

        try {
            $this->triggerValidator->validate($type, $config);
        } catch (InvalidArgumentException $exception) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config",
                code: 'invalid_trigger_config',
                message: $exception->getMessage(),
            );

            return;
        }

        $this->validateUniqueMessageTriggerNeedles($type, $config, $path, $flowId, $errors);
        $this->validateExistingEventTriggerSelection($type, $config, $path, $flowId, $errors);
        $this->validateEventTriggerAssistantFilter($type, $config, $path, $flowId, $errors);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateUniqueMessageTriggerNeedles(
        string $type,
        array $config,
        string $path,
        ?string $flowId,
        array &$errors,
    ): void {
        if (FlowTriggerType::Message->value !== $type || null === $flowId) {
            return;
        }

        $draft = FlowDraft::query()
            ->where('flow_id', $flowId)
            ->first();

        if (null === $draft) {
            return;
        }

        $currentNeedles = $this->normalizedTriggerNeedles($config);

        if ([] === $currentNeedles) {
            return;
        }

        $query = FlowTrigger::query()
            ->where('tenant_id', $draft->tenant_id)
            ->where('type', FlowTriggerType::Message->value)
            ->where('flow_id', '!=', $flowId);

        if (null === $draft->assistant_id) {
            $query->whereNull('assistant_id');
        } else {
            $query->where('assistant_id', $draft->assistant_id);
        }

        $conflicts = [];

        /** @var FlowTrigger $existingTrigger */
        foreach ($query->get() as $existingTrigger) {
            $duplicates = array_intersect(
                $currentNeedles,
                $this->normalizedTriggerNeedles($existingTrigger->config),
            );

            foreach ($duplicates as $duplicate) {
                $conflicts[] = $duplicate;
            }
        }

        $conflicts = array_values(array_unique($conflicts));

        if ([] === $conflicts) {
            return;
        }

        $errors[] = new FlowValidationErrorDto(
            path: "{$path}.config.keywords",
            code: 'duplicate_exact_trigger_keyword',
            message: 'Exact message trigger keywords must be unique within the same scope: ' . implode(', ', $conflicts)
                     . '.',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<string>
     */
    private function normalizedTriggerNeedles(array $config): array
    {
        $keywords = $config['keywords'] ?? null;
        $phrases  = $config['phrases'] ?? null;

        if (! is_array($keywords) || (null !== $phrases && ! is_array($phrases))) {
            return [];
        }

        $needles = [];

        foreach ([...$keywords, ...(is_array($phrases) ? $phrases : [])] as $value) {
            if (! is_string($value) || '' === mb_trim($value)) {
                continue;
            }

            $needles[] = $this->normalizeTriggerText($value);
        }

        return array_values(array_unique($needles));
    }

    private function normalizeTriggerText(string $value): string
    {
        $normalized = mb_strtolower(mb_trim($value));
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;

        return preg_replace('/\s+/u', ' ', mb_trim($normalized)) ?? mb_trim($normalized);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateExistingEventTriggerSelection(
        string $type,
        array $config,
        string $path,
        ?string $flowId,
        array &$errors,
    ): void {
        if (FlowTriggerType::Event->value !== $type || null === $flowId) {
            return;
        }

        $draft = FlowDraft::query()
            ->where('flow_id', $flowId)
            ->first();

        if (null === $draft) {
            return;
        }

        $eventName = $config['event_name'] ?? null;

        if (! is_string($eventName) || '' === mb_trim($eventName)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.event_name",
                code: 'missing_event_trigger_selection',
                message: 'Event trigger requires selecting an existing event.',
            );

            return;
        }

        $availableEvents = $this->tenantEvents->getEventNamesByTenant($draft->tenant_id);

        if (! in_array($eventName, $availableEvents, true)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.event_name",
                code: 'unknown_event_trigger_selection',
                message: 'Selected event must already exist in the tenant event registry.',
            );
        }
    }

    /**
     * The `assistant_filter` of an event trigger ({@see EventTriggerAssistantFilter}): refused on a Core event, which
     * has no source assistant, and every id must be an assistant of the flow's tenant.
     *
     * @param  array<string, mixed>  $config
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateEventTriggerAssistantFilter(
        string $type,
        array $config,
        string $path,
        ?string $flowId,
        array &$errors,
    ): void {
        if (FlowTriggerType::Event->value !== $type) {
            return;
        }

        $filter = EventTriggerAssistantFilter::fromConfig($config);

        if ($filter->isEmpty()) {
            return;
        }

        $filterPath = "{$path}.config." . EventTriggerAssistantFilter::CONFIG_KEY;
        $eventName  = $config['event_name'] ?? null;

        if (is_string($eventName) && CoreEventCatalog::has($eventName)) {
            $errors[] = new FlowValidationErrorDto(
                path: $filterPath,
                code: 'assistant_filter_on_core_event',
                message: 'A Core event has no source assistant: remove the assistant filter from its trigger.',
            );

            return;
        }

        if (null === $flowId) {
            return;
        }

        $draft = FlowDraft::query()
            ->where('flow_id', $flowId)
            ->first();

        if (null === $draft) {
            return;
        }

        $candidates = array_values(array_filter($filter->assistantIds, Str::isUuid(...)));

        $known = [] === $candidates
            ? []
            : array_map(
                static fn (mixed $id): string => mb_strtolower((string) $id),
                Assistant::query()
                    ->where('tenant_id', $draft->tenant_id)
                    ->whereIn('id', $candidates)
                    ->pluck('id')
                    ->all(),
            );

        foreach ($filter->assistantIds as $assistantId) {
            if (in_array(mb_strtolower($assistantId), $known, true)) {
                continue;
            }

            $errors[] = new FlowValidationErrorDto(
                path: $filterPath,
                code: 'unknown_assistant_filter',
                message: "The assistant filter names an assistant that does not exist: '{$assistantId}'.",
            );
        }
    }

    /**
     * Validate a loop node's own config (rule 8.3: iterator_name format).
     *
     * @param  array<string, mixed>          $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateLoopConfig(array $node, string $path, array &$errors): void
    {
        if (($node['type'] ?? null) !== LoopNodeHandler::TYPE) {
            return;
        }

        $config       = is_array($node['config'] ?? null) ? $node['config'] : [];
        $iteratorName = $config['iterator_name'] ?? null;

        if (null !== $iteratorName) {
            if (! is_string($iteratorName) || 1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $iteratorName)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.config.iterator_name",
                    code: 'loop_invalid_iterator_name',
                    message: "iterator_name '{$iteratorName}' must match identifier pattern (alphanumeric + underscore, not starting with digit).",
                );
            } elseif (in_array($iteratorName, ['id', 'language', 'meta', 'tenant_id', 'external_id'], true)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.config.iterator_name",
                    code: 'loop_reserved_iterator_name',
                    message: "iterator_name '{$iteratorName}' is reserved and cannot be used.",
                );
            }
        }

        // Rule 8.8: a literal count above the platform's loop iteration limit would fail every run at that limit.
        $mode        = is_string($config['mode'] ?? null) ? $config['mode'] : 'counted';
        $countSource = is_array($config['count_source'] ?? null) ? $config['count_source'] : null;

        if ('counted' === $mode && is_array($countSource) && 'literal' === ($countSource['type'] ?? null)) {
            $literal = $countSource['value'] ?? null;

            $budget = $this->platformSettings?->integer(FlowEngineInterface::MAX_LOOP_ITERATIONS_SETTING) ?? self::DEFAULT_LOOP_ITERATIONS;

            if (is_numeric($literal) && (int) $literal > $budget) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.config.count_source",
                    code: 'loop_count_exceeds_budget',
                    message: "Static loop count {$literal} exceeds the maximum of {$budget} iterations per loop.",
                );
            }
        }
    }

    /**
     * Validate a loop_end node: loop_node_id must reference an existing loop node (rule 8.2).
     *
     * @param  array<string, mixed>                  $node
     * @param  array<string, array<string, mixed>>   $nodeMap
     * @param  list<FlowValidationErrorDto>          $errors
     */
    private function validateLoopEndConfig(
        array $node,
        string $path,
        array $nodeMap,
        array &$errors,
    ): void {
        if (($node['type'] ?? null) !== LoopEndNodeHandler::TYPE) {
            return;
        }

        $config     = is_array($node['config'] ?? null) ? $node['config'] : [];
        $loopNodeId = is_string($config['loop_node_id'] ?? null) ? $config['loop_node_id'] : null;

        if (null === $loopNodeId || '' === $loopNodeId) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.loop_node_id",
                code: 'loop_end_missing_loop_node_id',
                message: 'loop_end requires loop_node_id pointing to the parent loop node.',
            );

            return;
        }

        $target = $nodeMap[$loopNodeId] ?? null;

        if (null === $target || ($target['type'] ?? null) !== LoopNodeHandler::TYPE) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.loop_node_id",
                code: 'loop_end_invalid_loop_node_id',
                message: "loop_node_id '{$loopNodeId}' does not reference an existing loop node.",
            );
        }
    }

    /**
     * Detect nested loops (rule 8.4): a loop body must not reach another loop node
     * before hitting a loop_end. Uses BFS from the 'loop' handle of each loop node.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<int, array<string, mixed>>     $edges
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function validateNoNestedLoops(array $nodeMap, array $edges, array &$errors): void
    {
        // Build adjacency list: source node id → list of target node ids.
        /** @var array<string, list<string>> $adjacency */
        $adjacency = [];

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $source = is_string($edge['from'] ?? null) ? $edge['from'] : null;
            $target = is_string($edge['to'] ?? null) ? $edge['to'] : null;

            if (null !== $source && null !== $target) {
                $adjacency[$source][] = $target;
            }
        }

        foreach ($nodeMap as $nodeId => $node) {
            if (($node['type'] ?? null) !== LoopNodeHandler::TYPE) {
                continue;
            }

            // BFS from each outgoing edge of this loop node's 'loop' handle.
            $loopEdgeTargets = [];

            foreach ($edges as $edge) {
                if (! is_array($edge)) {
                    continue;
                }

                if (($edge['from'] ?? null) !== $nodeId) {
                    continue;
                }

                if (($edge['handle'] ?? null) !== 'loop') {
                    continue;
                }

                $target = is_string($edge['to'] ?? null) ? $edge['to'] : null;

                if (null !== $target) {
                    $loopEdgeTargets[] = $target;
                }
            }

            $visited = [$nodeId => true];
            $queue   = $loopEdgeTargets;

            while ([] !== $queue) {
                $current = array_shift($queue);

                if (isset($visited[$current])) {
                    continue;
                }

                $visited[$current] = true;
                $currentType       = $nodeMap[$current]['type'] ?? null;

                if (LoopNodeHandler::TYPE === $currentType) {
                    $errors[] = new FlowValidationErrorDto(
                        path: "nodes.{$nodeId}",
                        code: 'loop_nested_not_allowed',
                        message: "Loop body of node '{$nodeId}' contains a nested loop node '{$current}'. Nested loops are not supported.",
                    );
                    break;
                }

                // Stop BFS at loop_end (it's the boundary of this loop's body).
                if (LoopEndNodeHandler::TYPE === $currentType) {
                    $loopEndConfig = is_array($nodeMap[$current]['config'] ?? null)
                        ? $nodeMap[$current]['config']
                        : [];

                    if (($loopEndConfig['loop_node_id'] ?? null) === $nodeId) {
                        continue;
                    }
                }

                foreach ($adjacency[$current] ?? [] as $next) {
                    if (! isset($visited[$next])) {
                        $queue[] = $next;
                    }
                }
            }
        }
    }

    /**
     * Reachability (rule 8.1): every loop node's `loop` handle must reach its
     * paired loop_end. The builder guarantees this via auto-managed constructs,
     * but flows arriving through the API / import path are unprotected — an
     * orphaned loop would iterate forever without a loop_end to advance it.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<int, array<string, mixed>>     $edges
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function validateLoopReachesLoopEnd(array $nodeMap, array $edges, array &$errors): void
    {
        // Build adjacency list: source node id → list of target node ids.
        /** @var array<string, list<string>> $adjacency */
        $adjacency = [];

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $source = is_string($edge['from'] ?? null) ? $edge['from'] : null;
            $target = is_string($edge['to'] ?? null) ? $edge['to'] : null;

            if (null !== $source && null !== $target) {
                $adjacency[$source][] = $target;
            }
        }

        foreach ($nodeMap as $nodeId => $node) {
            if (($node['type'] ?? null) !== LoopNodeHandler::TYPE) {
                continue;
            }

            // Seed BFS from the targets of this loop's 'loop' handle only.
            $queue = [];

            foreach ($edges as $edge) {
                if (! is_array($edge) || ($edge['from'] ?? null) !== $nodeId) {
                    continue;
                }

                if (($edge['handle'] ?? null) !== 'loop') {
                    continue;
                }

                $target = is_string($edge['to'] ?? null) ? $edge['to'] : null;

                if (null !== $target) {
                    $queue[] = $target;
                }
            }

            $reached = false;
            $visited = [$nodeId => true];

            while ([] !== $queue) {
                $current = array_shift($queue);

                if (isset($visited[$current])) {
                    continue;
                }

                $visited[$current] = true;

                if (($nodeMap[$current]['type'] ?? null) === LoopEndNodeHandler::TYPE) {
                    $config = is_array($nodeMap[$current]['config'] ?? null)
                        ? $nodeMap[$current]['config']
                        : [];

                    if (($config['loop_node_id'] ?? null) === $nodeId) {
                        $reached = true;
                        break;
                    }

                    // A loop_end for a *different* loop is a boundary — don't
                    // traverse past it into another loop's body.
                    continue;
                }

                foreach ($adjacency[$current] ?? [] as $next) {
                    if (! isset($visited[$next])) {
                        $queue[] = $next;
                    }
                }
            }

            if (! $reached) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$nodeId}",
                    code: 'loop_missing_loop_end',
                    message: "Loop node '{$nodeId}' has no reachable loop_end — the iteration would never complete.",
                );
            }
        }
    }
}
