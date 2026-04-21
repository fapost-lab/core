<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\DTOs\FlowValidationResultDto;
use LogicException;

final readonly class ValidateFlowService
{
    public function __construct(
        private NodeHandlerRegistryInterface $registry,
        private DataAccessorRegistryInterface $dataAccessors,
    ) {
    }

    /**
     * @param  array<string, mixed>  $nodes
     */
    public function execute(array $nodes): FlowValidationResultDto
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
}
