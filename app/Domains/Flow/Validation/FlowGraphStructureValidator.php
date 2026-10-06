<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use App\Domains\Flow\State\Variables\Variable;
use InvalidArgumentException;

/**
 * Structural checks on a stored flow graph: edge shape and uniqueness, the single
 * derived entry node, reachability and the variable contract of the nodes that save
 * user data.
 *
 * Works on the stored shape: nodes are `{id, type, config, ...}` and edges are
 * `{from, to, handle}` with `handle` defaulting to `default`. Builder-only annotation
 * nodes are stripped first for drafts, exactly as publish does (published definitions are
 * validated as stored, see `$stripAnnotations`), so a comment bridged between two
 * steps does not cut the graph. The entry rule mirrors
 * {@see \App\Domains\Flow\Services\FlowGraphResolver::resolveEntryNode()}: a node with
 * no incoming edge, of which there must be exactly one.
 *
 * Collects every problem and never throws. Node type, version and per-node config are
 * {@see \App\Domains\Flow\Services\ValidateFlowService}'s concern and are not repeated
 * here, including the `input` save target and the `branch` minimum of one rule.
 */
final readonly class FlowGraphStructureValidator
{
    private const array BRANCH_ALLOWED_SOURCES = [
        'contact',
        'rag',
        'call',
        'system',
        'flow',
    ];

    /**
     * @param  array<int|string, mixed>  $nodes
     * @param  array<int|string, mixed>  $edges
     *
     * @param  bool  $stripAnnotations  true for builder drafts, which still carry annotation nodes;
     *                                  false for published definitions, which publish already stripped
     *
     * @return list<FlowValidationErrorDto>
     */
    public function validate(array $nodes, array $edges, bool $stripAnnotations = true): array
    {
        if ($stripAnnotations) {
            [$nodes, $edges] = AnnotationNodeTypes::strip($nodes, $edges);
        }

        $errors  = [];
        $nodeMap = $this->indexNodes($nodes, $errors);

        $this->checkGraph($nodeMap, $edges, $errors);

        foreach ($nodeMap as $nodeId => $node) {
            array_push($errors, ...$this->variableContractErrors((string) $nodeId, $node));
        }

        return $errors;
    }

    /**
     * @param  array<int|string, mixed>      $nodes
     * @param  list<FlowValidationErrorDto>  $errors
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexNodes(array $nodes, array &$errors): array
    {
        $nodeMap = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = $node['id'] ?? null;

            if (! is_string($id) || '' === $id) {
                continue;
            }

            if (isset($nodeMap[$id])) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$id}",
                    code: 'duplicate_node_id',
                    message: "More than one node has the id '{$id}'.",
                );

                continue;
            }

            $nodeMap[$id] = $node;
        }

        return $nodeMap;
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<int|string, mixed>             $edges
     * @param  list<FlowValidationErrorDto>         $errors
     */
    private function checkGraph(array $nodeMap, array $edges, array &$errors): void
    {
        $adjacency = [];
        $incoming  = [];
        $seen      = [];

        foreach ($edges as $index => $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $from = is_string($edge['from'] ?? null) ? $edge['from'] : '';
            $to   = is_string($edge['to'] ?? null) ? $edge['to'] : '';
            // Read exactly like FlowGraphResolver::resolveNextNode: only a string handle can match.
            $handle = $edge['handle'] ?? 'default';
            $path   = "edges.{$index}";

            // Same incoming rule as the runtime: any edge with a target counts.
            if ('' !== $to) {
                $incoming[$to] = true;
            }

            $endpointsKnown = true;

            foreach (['from' => $from, 'to' => $to] as $side => $nodeId) {
                if (! isset($nodeMap[$nodeId])) {
                    $endpointsKnown = false;
                    $errors[]       = new FlowValidationErrorDto(
                        path: $path,
                        code: 'edge_unknown_node',
                        message: "Edge '{$side}' points at '{$nodeId}', which is not a node of this flow.",
                    );
                }
            }

            if ('' !== $from && is_string($handle)) {
                $key = $from . "\0" . $handle;

                if (isset($seen[$key])) {
                    $errors[] = new FlowValidationErrorDto(
                        path: $path,
                        code: 'duplicate_edge_handle',
                        message: "Node '{$from}' has more than one edge on handle '{$handle}'.",
                    );
                }

                $seen[$key] = true;
            }

            if ($endpointsKnown) {
                $adjacency[$from][] = $to;
            }
        }

        $entries = [];

        foreach (array_keys($nodeMap) as $nodeId) {
            if (! isset($incoming[$nodeId])) {
                $entries[] = (string) $nodeId;
            }
        }

        if (1 !== count($entries)) {
            $errors[] = new FlowValidationErrorDto(
                path: 'nodes',
                code: 'entry_node_count',
                message: sprintf(
                    'A flow needs exactly one entry node (no incoming edge), found %d%s.',
                    count($entries),
                    [] === $entries ? '' : ': ' . implode(', ', $entries),
                ),
            );

            return;
        }

        $reached = [$entries[0] => true];
        $queue   = [$entries[0]];

        while ([] !== $queue) {
            $current = array_pop($queue);

            foreach ($adjacency[$current] ?? [] as $next) {
                if (! isset($reached[$next])) {
                    $reached[$next] = true;
                    $queue[]        = $next;
                }
            }
        }

        foreach (array_keys($nodeMap) as $nodeId) {
            if (! isset($reached[$nodeId])) {
                $errors[] = new FlowValidationErrorDto(
                    path: "nodes.{$nodeId}",
                    code: 'orphan_node',
                    message: "Node '{$nodeId}' cannot be reached from the entry node '{$entries[0]}'.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     *
     * @return list<FlowValidationErrorDto>
     */
    private function variableContractErrors(string $nodeId, array $node): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        return match ($node['type'] ?? null) {
            'input'        => $this->inputErrors($nodeId, $config),
            'send_message' => $this->sendMessageErrors($nodeId, $config),
            'assign'       => $this->assignErrors($nodeId, $config),
            'branch'       => $this->branchRuleErrors($nodeId, $config),
            default        => [],
        };
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<FlowValidationErrorDto>
     */
    private function inputErrors(string $nodeId, array $config): array
    {
        $hasNew    = is_array($config['variable'] ?? null);
        $hasLegacy = is_string($config['save_to'] ?? null) && '' !== $config['save_to'];

        if ($hasNew && $hasLegacy) {
            return [$this->error(
                "nodes.{$nodeId}.config",
                'variable_contract_conflict',
                "Node {$nodeId} (input) cannot define both 'variable' and 'save_to' simultaneously.",
            )];
        }

        if (! $hasNew) {
            return [];
        }

        return $this->variableShapeErrors($nodeId, $config['variable'], 'variable', 'config.variable')[1];
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<FlowValidationErrorDto>
     */
    private function sendMessageErrors(string $nodeId, array $config): array
    {
        $hasNew    = is_array($config['save_to_variable'] ?? null);
        $hasLegacy = is_string($config['save_to'] ?? null) && '' !== $config['save_to'];

        if ($hasNew && $hasLegacy) {
            return [$this->error(
                "nodes.{$nodeId}.config",
                'variable_contract_conflict',
                "Node {$nodeId} (send_message) cannot define both 'save_to_variable' and 'save_to' simultaneously.",
            )];
        }

        if (! $hasNew) {
            return [];
        }

        return $this->variableShapeErrors(
            $nodeId,
            $config['save_to_variable'],
            'save_to_variable',
            'config.save_to_variable',
        )[1];
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<FlowValidationErrorDto>
     */
    private function assignErrors(string $nodeId, array $config): array
    {
        $hasNew    = is_array($config['operations'] ?? null);
        $hasLegacy = is_string($config['target'] ?? null) || is_string($config['key'] ?? null);

        if ($hasNew && $hasLegacy) {
            return [$this->error(
                "nodes.{$nodeId}.config",
                'variable_contract_conflict',
                "Node {$nodeId} (assign) cannot define both 'operations' and 'target'/'key' simultaneously.",
            )];
        }

        if (! $hasNew) {
            return [];
        }

        $errors = [];
        $seen   = [];

        foreach ($config['operations'] as $index => $operation) {
            $path = "config.operations.{$index}";

            if (! is_array($operation)) {
                $errors[] = $this->error(
                    "nodes.{$nodeId}.{$path}",
                    'variable_contract_invalid_operation',
                    "Node {$nodeId} (assign) operation #{$index} must be an object.",
                );

                continue;
            }

            $variableConfig = $operation['variable'] ?? null;

            if (! is_array($variableConfig)) {
                $errors[] = $this->error(
                    "nodes.{$nodeId}.{$path}.variable",
                    'variable_contract_invalid_operation',
                    "Node {$nodeId} (assign) operation #{$index} is missing variable definition.",
                );

                continue;
            }

            [$variable, $shapeErrors] = $this->variableShapeErrors(
                $nodeId,
                $variableConfig,
                "operations[{$index}].variable",
                "{$path}.variable",
            );

            array_push($errors, ...$shapeErrors);

            if (! $variable instanceof Variable) {
                continue;
            }

            $signature = $variable->storage->value . '|' . ($variable->group ?? '') . '|' . $variable->name;

            if (isset($seen[$signature])) {
                $errors[] = $this->error(
                    "nodes.{$nodeId}.{$path}.variable",
                    'variable_contract_duplicate_target',
                    "Node {$nodeId} (assign) defines duplicate variable target '{$signature}' across operations.",
                );
            }

            $seen[$signature] = true;
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<FlowValidationErrorDto>
     */
    private function branchRuleErrors(string $nodeId, array $config): array
    {
        $rules = $config['rules'] ?? null;

        if (! is_array($rules)) {
            return [];
        }

        $errors = [];

        foreach ($rules as $index => $rule) {
            $base = "nodes.{$nodeId}.config.rules.{$index}";

            if (! is_array($rule)) {
                $errors[] = $this->error(
                    $base,
                    'branch_rules_invalid_rule',
                    "Node {$nodeId} (branch) rule #{$index} must be an object.",
                );

                continue;
            }

            $left = $rule['left'] ?? null;

            if (! is_array($left)) {
                continue; // legacy: rule without left, falls back to top-level `check`.
            }

            $ref = $left['ref'] ?? null;

            if ('user_variable' === $ref) {
                $variableConfig = is_array($left['variable'] ?? null)
                    ? $left['variable']
                    : [
                        'name'    => $left['name'] ?? null,
                        'storage' => $left['storage'] ?? null,
                        'group'   => $left['group'] ?? null,
                    ];

                if (! is_string($variableConfig['name'] ?? null) || '' === $variableConfig['name']) {
                    $errors[] = $this->error(
                        "{$base}.left",
                        'branch_rules_missing_variable_name',
                        "Node {$nodeId} (branch) rule #{$index} user_variable left requires a name.",
                    );

                    continue;
                }

                // Storage is optional here; when present the whole Variable is validated.
                if (is_string($variableConfig['storage'] ?? null) && '' !== $variableConfig['storage']) {
                    array_push($errors, ...$this->variableShapeErrors(
                        $nodeId,
                        $variableConfig,
                        "rules[{$index}].left.variable",
                        "config.rules.{$index}.left.variable",
                    )[1]);
                }

                continue;
            }

            if ('source' === $ref) {
                $source = $left['source'] ?? null;
                $field  = $left['field'] ?? null;

                if (! is_string($source) || '' === $source) {
                    $errors[] = $this->error(
                        "{$base}.left.source",
                        'branch_rules_missing_source',
                        "Node {$nodeId} (branch) rule #{$index} source left requires a non-empty source.",
                    );

                    continue;
                }

                if (! is_string($field) || '' === $field) {
                    $errors[] = $this->error(
                        "{$base}.left.field",
                        'branch_rules_missing_field',
                        "Node {$nodeId} (branch) rule #{$index} source left requires a non-empty field.",
                    );

                    continue;
                }

                if (! in_array($source, self::BRANCH_ALLOWED_SOURCES, true) && ! str_starts_with($source, 'module.')) {
                    $errors[] = $this->error(
                        "{$base}.left.source",
                        'branch_rules_source_not_allowed',
                        "Node {$nodeId} (branch) rule #{$index} source '{$source}' is not allowed.",
                    );
                }

                continue;
            }

            $errors[] = $this->error(
                "{$base}.left.ref",
                'branch_rules_invalid_ref',
                "Node {$nodeId} (branch) rule #{$index} left.ref must be 'user_variable' or 'source'.",
            );
        }

        return $errors;
    }

    /**
     * @return array{0: Variable|null, 1: list<FlowValidationErrorDto>}
     */
    private function variableShapeErrors(string $nodeId, mixed $raw, string $field, string $pathSuffix): array
    {
        $path = "nodes.{$nodeId}.{$pathSuffix}";

        if (! is_array($raw)) {
            return [null, [$this->error(
                $path,
                'variable_contract_invalid',
                "Node {$nodeId} {$field} must be an object describing a variable.",
            )]];
        }

        try {
            $variable = Variable::tryFromArray($raw);
        } catch (InvalidArgumentException $exception) {
            return [null, [$this->error(
                $path,
                'variable_contract_invalid',
                "Node {$nodeId} {$field} is invalid: {$exception->getMessage()}",
            )]];
        }

        if (! $variable instanceof Variable) {
            return [null, [$this->error(
                $path,
                'variable_contract_invalid',
                "Node {$nodeId} {$field} is missing required name/storage.",
            )]];
        }

        return [$variable, []];
    }

    private function error(string $path, string $code, string $message): FlowValidationErrorDto
    {
        return new FlowValidationErrorDto(path: $path, code: $code, message: $message);
    }
}
