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
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use InvalidArgumentException;
use LogicException;

final readonly class ValidateFlowService
{
    public function __construct(
        private NodeHandlerRegistryInterface $registry,
        private DataAccessorRegistryInterface $dataAccessors,
        private FlowTriggerConfigValidatorInterface $triggerValidator,
        private TenantEventRepositoryInterface $tenantEvents,
    ) {
    }

    /**
     * @param  array<string, mixed>  $nodes
     * @param  array<string, mixed>|null  $trigger
     */
    public function execute(array $nodes, ?array $trigger = null, ?string $flowId = null): FlowValidationResultDto
    {
        $errors  = [];
        $nodeMap = $this->normalizeNodeMap($nodes, $errors);

        foreach ($nodeMap as $nodeId => $node) {
            $path = "nodes.{$nodeId}";
            $this->validateRegisteredNodeType($node, $path, $errors);
            $this->validateRequiredConfig($node, $path, $errors);
            $this->validateOutputs($node, $path, $nodeMap, $errors);
            $this->validateConditionNodeConnections($node, $path, $errors);
            $this->validateUnknownModuleReferences($node, $path, $errors);
        }

        $this->validateTrigger($trigger, $errors, $flowId);

        return new FlowValidationResultDto(
            valid: [] === $errors,
            errors: $errors,
        );
    }

    /**
     * @param  list<FlowValidationErrorDto>  $errors
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
     * @param  array<string, mixed>  $node
     * @param  list<FlowValidationErrorDto>  $errors
     */
    private function validateConditionNodeConnections(array $node, string $path, array &$errors): void
    {
        if (($node['type'] ?? null) !== 'condition') {
            return;
        }

        $outputs = $node['outputs'] ?? null;
        if ( ! is_array($outputs) || ! isset($outputs['true'], $outputs['false'])) {
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.outputs",
                code: 'condition_outputs_missing',
                message: 'Condition nodes must have both true/false outputs.',
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
            message: 'Exact message trigger keywords must be unique within the same scope: ' . implode(', ', $conflicts) . '.',
        );
    }

    /**
     * @param  array<string, mixed>  $config
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
