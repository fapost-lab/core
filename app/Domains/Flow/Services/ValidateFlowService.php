<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\DTOs\FlowValidationResultDto;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Handlers\EndNodeHandler;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Subflow\CallGraphValidator;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Exception;
use InvalidArgumentException;
use LogicException;

final readonly class ValidateFlowService
{
    public function __construct(
        private NodeHandlerRegistryInterface $registry,
        private DataAccessorRegistryInterface $dataAccessors,
        private FlowTriggerConfigValidatorInterface $triggerValidator,
        private TenantEventRepositoryInterface $tenantEvents,
        private TenantContextInterface $tenantContext,
        private ?CallGraphValidator $callGraphValidator = null,
    ) {
    }

    /**
     * @param  array<string, mixed>              $nodes
     * @param  array<string, mixed>|null         $trigger
     * @param  array<int, array<string, mixed>>  $edges
     */
    public function execute(
        array $nodes,
        ?array $trigger = null,
        ?string $flowId = null,
        array $edges = []
    ): FlowValidationResultDto {
        $errors  = [];
        $nodeMap = $this->normalizeNodeMap($nodes, $errors);

        foreach ($nodeMap as $nodeId => $node) {
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
        }

        $this->validateEndNodeOutgoingEdges($nodeMap, $edges, $errors);
        $this->validateSubflowCallGraph($flowId, $nodeMap, $errors);

        $this->validateInlineKeyboardIsTerminal($nodeMap, $edges, $errors);
        $this->validateButtonEdgesMatchExistingButtons($nodeMap, $edges, $errors);
        $this->validateButtonValueTypes($nodeMap, $errors);
        $this->validateTrigger($trigger, $errors, $flowId);

        return new FlowValidationResultDto(
            valid: [] === $errors,
            errors: $errors,
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
            if ( ! is_array($node)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$key}",
                    code: 'invalid_node',
                    message: 'Node payload must be an object.',
                );
                continue;
            }

            $nodeId = $node['id'] ?? $key;
            if ( ! is_string($nodeId) || '' === $nodeId) {
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

        if ( ! is_string($type) || '' === $type || ! is_int($version)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.type",
                code: 'invalid_handler_reference',
                message: 'Node type/version is invalid.',
            );

            return;
        }

        try {
            $this->registry->resolve($type, $version);
        } catch (LogicException) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.type",
                code: 'unknown_node_type',
                message: "Node handler is not registered for {$type}@{$version}.",
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
        if ( ! is_string($type) || ! is_int($version)) {
            return;
        }

        if ( ! is_array($config)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config",
                code: 'invalid_config',
                message: 'Node config must be an object.',
            );

            return;
        }

        try {
            $handler = $this->registry->resolve($type, $version);
        } catch (LogicException) {
            return;
        }

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
            if ( ! array_key_exists($requiredField, $config)) {
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
        if ( ! is_array($outputs)) {
            return;
        }

        foreach ($outputs as $handle => $output) {
            if ( ! is_array($output)) {
                continue;
            }

            $target = $output['next'] ?? $output['target'] ?? null;
            if ( ! is_string($target) || '' === $target || ! isset($nodeMap[$target])) {
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
        if (($node['type'] ?? null) !== 'branch') {
            return;
        }

        $outputs = $node['outputs'] ?? null;
        if ( ! is_array($outputs) || ! isset($outputs['true'], $outputs['false'])) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.outputs",
                code: 'branch_outputs_missing',
                message: 'Branch nodes must have both true/false outputs.',
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
        if ( ! is_array($config)) {
            return;
        }

        array_walk_recursive($config, function (mixed $value) use (&$errors, $path): void {
            if ( ! is_string($value) || ! str_starts_with($value, 'module.')) {
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
            if ( ! $this->dataAccessors->has($namespace)) {
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

        if ( ! $contentType instanceof SendMessageContentType || ! $contentType->requiresMediaUrl()) {
            return;
        }

        $mediaFileId = $config['media_file_id'] ?? null;

        if ( ! is_string($mediaFileId) || '' === $mediaFileId) {
            return;
        }

        if ( ! $this->tenantContext->isResolved()) {
            return;
        }

        if ( ! MediaFile::query()->where('id', $mediaFileId)->exists()) {
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
     * naming convention (alphanumeric segments + dot). The full validity of
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

        if ( ! is_string($eventType) || '' === mb_trim($eventType)) {
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

        if ( ! is_string($status) || ! in_array($status, EndNodeHandler::ALLOWED_END_STATUSES, true)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.status",
                code: 'end_invalid_status',
                message: 'end.status must be one of: ' . implode(', ', EndNodeHandler::ALLOWED_END_STATUSES) . '.',
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
        if ( ! is_string($kb) || '' === mb_trim($kb)) {
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
        if ( ! is_string($provider) || '' === mb_trim($provider)) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.provider",
                code: 'rag_missing_provider',
                message: 'rag_query requires a non-empty provider id.',
            );
        }

        $query = $config['query'] ?? null;
        if ( ! is_string($query) || '' === mb_trim($query)) {
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
        if ( ! is_string($flowId) || '' === mb_trim($flowId)) {
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

        $timeout = $config['timeout'] ?? null;
        if (null !== $timeout) {
            if ( ! is_string($timeout) || '' === mb_trim($timeout)) {
                $errors[] = new FlowValidationErrorDto(
                    path: "{$path}.config.timeout",
                    code: 'subflow_invalid_timeout',
                    message: 'timeout must be a non-empty ISO 8601 duration string (e.g. PT24H).',
                );
            } else {
                try {
                    \App\Domains\Flow\Subflow\SubflowStarterService::parseTimeout($timeout);
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
            if ( ! is_array($edge) || ! isset($edge['from']) || ! is_string($edge['from'])) {
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
            if ( ! isset($nodesWithIncoming[$nodeId])) {
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
                if ( ! is_array($edge)) {
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
            if ( ! isset($nodesWithIncoming[$nodeId])) {
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
            if ( ! is_array($edge)) {
                continue;
            }

            $fromId = $edge['from'] ?? null;
            $handle = $edge['handle'] ?? 'default';

            if ( ! is_string($fromId) || 'default' === $handle) {
                continue;
            }

            $node = $nodeMap[$fromId] ?? null;
            if (null === $node || ($node['type'] ?? null) !== 'send_message') {
                continue;
            }

            $config  = is_array($node['config'] ?? null) ? $node['config'] : [];
            $buttons = is_array($config['buttons'] ?? null) ? $config['buttons'] : [];

            $buttonIds = array_column($buttons, 'id');
            if ( ! in_array($handle, $buttonIds, true)) {
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
            if ( ! in_array($saveToType, ['string', 'number', 'boolean'], true) || 'string' === $saveToType) {
                continue;
            }

            $buttons = is_array($config['buttons'] ?? null) ? $config['buttons'] : [];

            foreach ($buttons as $idx => $button) {
                $value = $button['value'] ?? '';
                if ( ! is_string($value) || '' === $value) {
                    continue;
                }

                $valid = match ($saveToType) {
                    'number'  => is_numeric($value),
                    'boolean' => 'true' === $value || 'false' === $value,
                    default   => true,
                };

                if ( ! $valid) {
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
     * @param  array<string, mixed>|null  $trigger
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateTrigger(?array $trigger, array &$errors, ?string $flowId): void
    {
        if (null === $trigger) {
            return;
        }

        if (true === ($trigger['_delete'] ?? false)) {
            return;
        }

        $type = $trigger['type'] ?? null;
        if ( ! is_string($type) || '' === $type) {
            $errors[] = new FlowValidationErrorDto(
                path: 'trigger.type',
                code: 'invalid_trigger_type',
                message: 'Trigger type is required.',
            );

            return;
        }

        if ( ! is_bool($trigger['is_active'] ?? null)) {
            $errors[] = new FlowValidationErrorDto(
                path: 'trigger.is_active',
                code: 'invalid_trigger_active_flag',
                message: 'Trigger active flag must be boolean.',
            );
        }

        $priority = $trigger['priority'] ?? null;
        if ( ! is_int($priority) || $priority < 0) {
            $errors[] = new FlowValidationErrorDto(
                path: 'trigger.priority',
                code: 'invalid_trigger_priority',
                message: 'Trigger priority must be a non-negative integer.',
            );
        }

        $config = $trigger['config'] ?? null;
        if ( ! is_array($config)) {
            $errors[] = new FlowValidationErrorDto(
                path: 'trigger.config',
                code: 'invalid_trigger_config',
                message: 'Trigger config must be an object.',
            );

            return;
        }

        try {
            $this->triggerValidator->validate($type, $config);
        } catch (InvalidArgumentException $exception) {
            $errors[] = new FlowValidationErrorDto(
                path: 'trigger.config',
                code: 'invalid_trigger_config',
                message: $exception->getMessage(),
            );

            return;
        }

        $this->validateUniqueMessageTriggerNeedles($type, $config, $flowId, $errors);
        $this->validateExistingEventTriggerSelection($type, $config, $flowId, $errors);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateUniqueMessageTriggerNeedles(
        string $type,
        array $config,
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
            path: 'trigger.config.keywords',
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

        if ( ! is_array($keywords) || (null !== $phrases && ! is_array($phrases))) {
            return [];
        }

        $needles = [];

        foreach ([...$keywords, ...(is_array($phrases) ? $phrases : [])] as $value) {
            if ( ! is_string($value) || '' === mb_trim($value)) {
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

        if ( ! is_string($eventName) || '' === mb_trim($eventName)) {
            $errors[] = new FlowValidationErrorDto(
                path: 'trigger.config.event_name',
                code: 'missing_event_trigger_selection',
                message: 'Event trigger requires selecting an existing event.',
            );

            return;
        }

        $availableEvents = $this->tenantEvents->getEventNamesByTenant($draft->tenant_id);

        if ( ! in_array($eventName, $availableEvents, true)) {
            $errors[] = new FlowValidationErrorDto(
                path: 'trigger.config.event_name',
                code: 'unknown_event_trigger_selection',
                message: 'Selected event must already exist in the tenant event registry.',
            );
        }
    }
}
