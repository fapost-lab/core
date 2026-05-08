<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\State\Variables\Variable;
use InvalidArgumentException;
use LogicException;
use Throwable;

final readonly class FlowDefinitionValidator
{
    private const array BRANCH_ALLOWED_SOURCES = [
        'contact',
        'rag',
        'call',
        'system',
        'flow',
    ];
    public function __construct(
        private NodeHandlerRegistryInterface $registry,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     *
     * @return array{entry_node_id: string, adjacency: array<string, array<string, string>>, reverse_adjacency:
     *                              array<string, list<string>>}
     */
    public function validate(array $nodes, array $edges): array
    {
        if ([] === $nodes) {
            throw $this->validationException('Flow definition must contain at least one node.');
        }

        $nodeMap = [];

        foreach ($nodes as $node) {
            $nodeId  = $this->stringField($node, 'id', 'Node must contain id.');
            $type    = $this->stringField($node, 'type', "Node {$nodeId} must contain type.");
            $version = $this->intField($node, 'version', "Node {$nodeId} must contain integer version.");

            try {
                $this->registry->resolve($type, $version);
            } catch (LogicException $exception) {
                throw $this->validationException(
                    "Node {$nodeId} references unknown handler version: {$type}@{$version}",
                    $exception,
                );
            }

            if (isset($nodeMap[$nodeId])) {
                throw $this->validationException("Duplicate node id: {$nodeId}");
            }

            $nodeMap[$nodeId] = $node;
        }

        $adjacency        = [];
        $reverseAdjacency = [];

        foreach ($edges as $edge) {
            $sourceNodeId = $this->stringField($edge, 'source_node_id', 'Edge must contain source_node_id.');
            $targetNodeId = $this->stringField($edge, 'target_node_id', 'Edge must contain target_node_id.');
            $transition   = $this->stringField($edge, 'transition', 'Edge must contain transition.');

            if ( ! isset($nodeMap[$sourceNodeId])) {
                throw $this->validationException("Edge references unknown source node: {$sourceNodeId}");
            }

            if ( ! isset($nodeMap[$targetNodeId])) {
                throw $this->validationException("Edge references unknown target node: {$targetNodeId}");
            }

            if (isset($adjacency[$sourceNodeId][$transition])) {
                throw $this->validationException(
                    "Duplicate edge transition mapping for source {$sourceNodeId} and transition {$transition}"
                );
            }

            $adjacency[$sourceNodeId][$transition] = $targetNodeId;
            $reverseAdjacency[$targetNodeId] ??= [];
            $reverseAdjacency[$targetNodeId][] = $sourceNodeId;
        }

        $entryCandidates = [];

        foreach (array_keys($nodeMap) as $nodeId) {
            if ( ! isset($reverseAdjacency[$nodeId])) {
                $entryCandidates[] = $nodeId;
            }
        }

        if (1 !== count($entryCandidates)) {
            throw $this->validationException(
                'Flow definition must contain exactly one entry point node (node without incoming edges).'
            );
        }

        $entryNodeId = $entryCandidates[0];
        $visited     = [];
        $queue       = [$entryNodeId];

        while ([] !== $queue) {
            $current = array_shift($queue);

            if (null === $current || isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            foreach ($adjacency[$current] ?? [] as $targetNodeId) {
                $queue[] = $targetNodeId;
            }
        }

        if (count($visited) !== count($nodeMap)) {
            $orphanNodes = array_diff(array_keys($nodeMap), array_keys($visited));
            throw $this->validationException(
                'Flow definition contains orphan nodes: ' . implode(', ', $orphanNodes)
            );
        }

        foreach ($nodeMap as $nodeId => $node) {
            if ( ! isset($node['required_transitions']) || ! is_array($node['required_transitions'])) {
                continue;
            }

            foreach ($node['required_transitions'] as $transition) {
                if ( ! is_string($transition) || '' === $transition) {
                    throw $this->validationException("Node {$nodeId} has invalid required transition value.");
                }

                if ( ! isset($adjacency[$nodeId][$transition])) {
                    throw $this->validationException(
                        "Node {$nodeId} requires transition {$transition}, but edge is missing."
                    );
                }
            }
        }

        foreach ($nodeMap as $nodeId => $node) {
            if (($node['type'] ?? null) !== 'send_message') {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            if (($config['keyboard_mode'] ?? null) !== 'reply') {
                continue;
            }

            if (isset($adjacency[$nodeId]['default'])) {
                throw $this->validationException(
                    "send_message node with keyboard_mode=reply must be terminal — 'default' output cannot be connected"
                );
            }
        }

        foreach ($nodeMap as $nodeId => $node) {
            $this->validateVariableContract($nodeId, $node);
        }

        return [
            'entry_node_id'     => $entryNodeId,
            'adjacency'         => $adjacency,
            'reverse_adjacency' => $reverseAdjacency,
        ];
    }

    /**
     * Enforce the variable contract for nodes that participate in the
     * "save user data" pipeline. Rules:
     *  - new ({@code variable}/{@code save_to_variable}/{@code operations})
     *    and legacy ({@code save_to}/{@code target+key}) shapes cannot
     *    coexist on the same node;
     *  - new shape payloads must construct a valid {@see Variable} (name,
     *    storage, group depth ≤ 1, no reserved keys);
     *  - {@code operations[]} entries must address unique
     *    {@code (storage, group, name)} triples within one assign node.
     *
     * @param  array<string, mixed>  $node
     */
    private function validateVariableContract(string $nodeId, array $node): void
    {
        $type   = is_string($node['type'] ?? null) ? $node['type'] : null;
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        match ($type) {
            'input'        => $this->validateInputVariableConfig($nodeId, $config),
            'send_message' => $this->validateSendMessageVariableConfig($nodeId, $config),
            'assign'       => $this->validateAssignVariableConfig($nodeId, $config),
            'branch'       => $this->validateBranchRulesConfig($nodeId, $config),
            default        => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateBranchRulesConfig(string $nodeId, array $config): void
    {
        $rules = $config['rules'] ?? null;

        if ( ! is_array($rules)) {
            return;
        }

        foreach ($rules as $index => $rule) {
            if ( ! is_array($rule)) {
                throw $this->validationException(
                    "Node {$nodeId} (branch) rule #{$index} must be an object."
                );
            }

            $left = $rule['left'] ?? null;

            if ( ! is_array($left)) {
                continue; // legacy: rule without left, falls back to top-level `check`.
            }

            $ref = $left['ref'] ?? null;

            if ('user_variable' === $ref) {
                $variableConfig = is_array($left['variable'] ?? null)
                    ? $left['variable']
                    : ['name' => $left['name'] ?? null, 'storage' => $left['storage'] ?? null, 'group' => $left['group'] ?? null];

                if ( ! is_string($variableConfig['name'] ?? null) || '' === $variableConfig['name']) {
                    throw $this->validationException(
                        "Node {$nodeId} (branch) rule #{$index} user_variable left requires a name."
                    );
                }

                // storage is optional in storage; if present we validate Variable shape end-to-end.
                if (is_string($variableConfig['storage'] ?? null) && '' !== $variableConfig['storage']) {
                    $this->assertVariableShape($nodeId, $variableConfig, "rules[{$index}].left.variable");
                }

                continue;
            }

            if ('source' === $ref) {
                $source = $left['source'] ?? null;
                $field  = $left['field'] ?? null;

                if ( ! is_string($source) || '' === $source) {
                    throw $this->validationException(
                        "Node {$nodeId} (branch) rule #{$index} source left requires a non-empty source."
                    );
                }

                if ( ! is_string($field) || '' === $field) {
                    throw $this->validationException(
                        "Node {$nodeId} (branch) rule #{$index} source left requires a non-empty field."
                    );
                }

                $allowed = in_array($source, self::BRANCH_ALLOWED_SOURCES, true) || str_starts_with($source, 'module.');

                if ( ! $allowed) {
                    throw $this->validationException(
                        "Node {$nodeId} (branch) rule #{$index} source '{$source}' is not allowed."
                    );
                }

                continue;
            }

            throw $this->validationException(
                "Node {$nodeId} (branch) rule #{$index} left.ref must be 'user_variable' or 'source'."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateInputVariableConfig(string $nodeId, array $config): void
    {
        $hasNew    = is_array($config['variable'] ?? null);
        $hasLegacy = is_string($config['save_to'] ?? null) && '' !== $config['save_to'];

        if ($hasNew && $hasLegacy) {
            throw $this->validationException(
                "Node {$nodeId} (input) cannot define both 'variable' and 'save_to' simultaneously."
            );
        }

        if ($hasNew) {
            $this->assertVariableShape($nodeId, $config['variable'], 'variable');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateSendMessageVariableConfig(string $nodeId, array $config): void
    {
        $hasNew    = is_array($config['save_to_variable'] ?? null);
        $hasLegacy = is_string($config['save_to'] ?? null) && '' !== $config['save_to'];

        if ($hasNew && $hasLegacy) {
            throw $this->validationException(
                "Node {$nodeId} (send_message) cannot define both 'save_to_variable' and 'save_to' simultaneously."
            );
        }

        if ($hasNew) {
            $this->assertVariableShape($nodeId, $config['save_to_variable'], 'save_to_variable');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateAssignVariableConfig(string $nodeId, array $config): void
    {
        $hasNew    = is_array($config['operations'] ?? null);
        $hasLegacy = is_string($config['target'] ?? null) || is_string($config['key'] ?? null);

        if ($hasNew && $hasLegacy) {
            throw $this->validationException(
                "Node {$nodeId} (assign) cannot define both 'operations' and 'target'/'key' simultaneously."
            );
        }

        if ( ! $hasNew) {
            return;
        }

        $seen = [];

        foreach ($config['operations'] as $index => $operation) {
            if ( ! is_array($operation)) {
                throw $this->validationException(
                    "Node {$nodeId} (assign) operation #{$index} must be an object."
                );
            }

            $variableConfig = $operation['variable'] ?? null;

            if ( ! is_array($variableConfig)) {
                throw $this->validationException(
                    "Node {$nodeId} (assign) operation #{$index} is missing variable definition."
                );
            }

            $variable = $this->assertVariableShape($nodeId, $variableConfig, "operations[{$index}].variable");

            $signature = $variable->storage->value . '|' . ($variable->group ?? '') . '|' . $variable->name;

            if (isset($seen[$signature])) {
                throw $this->validationException(
                    "Node {$nodeId} (assign) defines duplicate variable target '{$signature}' across operations."
                );
            }

            $seen[$signature] = true;
        }
    }

    /**
     * @param  array<string, mixed>|mixed  $raw
     */
    private function assertVariableShape(string $nodeId, mixed $raw, string $field): Variable
    {
        if ( ! is_array($raw)) {
            throw $this->validationException(
                "Node {$nodeId} {$field} must be an object describing a variable."
            );
        }

        try {
            $variable = Variable::tryFromArray($raw);
        } catch (InvalidArgumentException $exception) {
            throw $this->validationException(
                "Node {$nodeId} {$field} is invalid: {$exception->getMessage()}",
                $exception,
            );
        }

        if ( ! $variable instanceof Variable) {
            throw $this->validationException(
                "Node {$nodeId} {$field} is missing required name/storage."
            );
        }

        return $variable;
    }

    private function validationException(string $message, ?Throwable $previous = null): FlowValidationException
    {
        return new FlowValidationException([
            new FlowValidationErrorDto(
                path: 'flow_definition',
                code: 'invalid_definition',
                message: $message,
            ),
        ], $previous);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $field, string $message): string
    {
        $value = $payload[$field] ?? null;

        if ( ! is_string($value) || '' === $value) {
            throw $this->validationException($message);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intField(array $payload, string $field, string $message): int
    {
        $value = $payload[$field] ?? null;

        if ( ! is_int($value)) {
            throw $this->validationException($message);
        }

        return $value;
    }
}
