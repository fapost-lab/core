<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\LoopNodeHandler;

/**
 * Catches loop mistakes at publish that would otherwise show up as a runtime failure or an endless loop
 * (loop node spec 8.3b, 8.5, 8.7).
 *
 * - {@see self::CODE_ITERATOR_CONFLICT} (error): a node writes a session variable named like a loop's
 *   `iterator_name` or `{iterator_name}_total`. Both live in the `flow.*` namespace, so the write would overwrite the
 *   loop's counter (or the loop would overwrite the variable).
 * - {@see self::CODE_COUNT_NOT_NUMERIC} (error): a counted loop's literal `count_source` is not a number; the handler
 *   would fail the session on the first pass.
 * - {@see self::CODE_WHILE_UNCHANGED} (warning): a `while` condition reads a variable that no node of the loop body
 *   writes, so the loop would run until the iteration budget stops it. It is only a warning because the value may
 *   change from outside (another flow writing to the contact); it is skipped when the body holds a Solution node
 *   or a `template` node, which may write anything, and when the condition reads something this check cannot follow (`module.*`, `rag.*`).
 *
 * Writes are read from the config shapes of the nodes that save a variable: `input`, `assign` (operations and the
 * legacy `target` / `key` form), `send_message` and `call` (including their legacy `save_to` / `save_response_to`
 * strings), `ask_person` (`save_to_variable`) and `subflow` (`output_mapping`). Annotation nodes must be removed by
 * the caller.
 */
final readonly class LoopConfigChecker
{
    public const string CODE_ITERATOR_CONFLICT = 'loop_iterator_variable_conflict';

    public const string CODE_COUNT_NOT_NUMERIC = 'loop_count_not_numeric';

    public const string CODE_WHILE_UNCHANGED = 'loop_while_condition_unchanged';

    public function __construct(
        private NodeHandlerRegistryInterface $registry,
        private NodeWriteCollector $writes = new NodeWriteCollector(),
    ) {
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap  the flow's nodes by id, annotations removed
     * @param  array<int, array<string, mixed>>     $edges
     *
     * @return array{errors: list<FlowValidationErrorDto>, warnings: list<FlowValidationErrorDto>}
     */
    public function check(array $nodeMap, array $edges): array
    {
        $errors   = [];
        $warnings = [];
        $written  = null;

        foreach ($nodeMap as $nodeId => $node) {
            if (LoopNodeHandler::TYPE !== ($node['type'] ?? null)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $path   = "nodes.{$nodeId}";
            $mode   = is_string($config['mode'] ?? null) ? $config['mode'] : 'counted';

            $written ??= $this->sessionWrites($nodeMap);

            array_push($errors, ...$this->iteratorConflicts($config, $path, $written, 'counted' === $mode));

            if ('counted' === $mode) {
                array_push($errors, ...$this->countErrors($config, $path));

                continue;
            }

            array_push($warnings, ...$this->whileWarnings((string) $nodeId, $config, $path, $nodeMap, $edges));
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>       $config
     * @param  array<string, list<string>>  $written  session variable name => ids of the nodes that write it
     *
     * @return list<FlowValidationErrorDto>
     */
    private function iteratorConflicts(array $config, string $path, array $written, bool $counted): array
    {
        $iterator = LoopNodeHandler::iteratorNameOf($config);
        $errors   = [];

        // Only a counted loop keeps a total.
        foreach ($counted ? [$iterator, $iterator . LoopNodeHandler::TOTAL_SUFFIX] : [$iterator] as $name) {
            if (! isset($written[$name])) {
                continue;
            }

            $writers  = implode("', '", $written[$name]);
            $errors[] = new FlowValidationErrorDto(
                path: "{$path}.config.iterator_name",
                code: self::CODE_ITERATOR_CONFLICT,
                message: "The loop keeps its counter in the session variable '{$name}', but node '{$writers}' also writes a "
                    . "session variable named '{$name}'. Rename the variable or the loop's iterator_name.",
            );
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<FlowValidationErrorDto>
     */
    private function countErrors(array $config, string $path): array
    {
        $source = $config['count_source'] ?? null;

        if (! is_array($source) || 'literal' !== ($source['type'] ?? null)) {
            return [];
        }

        $value = $source['value'] ?? null;

        if (is_numeric($value)) {
            return [];
        }

        $shown = is_scalar($value) ? "'" . (string) $value . "'" : 'an empty value';

        return [new FlowValidationErrorDto(
            path: "{$path}.config.count_source",
            code: self::CODE_COUNT_NOT_NUMERIC,
            message: "The loop's count is {$shown}, which is not a number. A counted loop needs a whole number of repetitions.",
        )];
    }

    /**
     * @param  array<string, mixed>                 $config
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<int, array<string, mixed>>     $edges
     *
     * @return list<FlowValidationErrorDto>
     */
    private function whileWarnings(string $loopId, array $config, string $path, array $nodeMap, array $edges): array
    {
        $condition = $config['condition'] ?? null;
        $watched   = is_array($condition) ? $this->watchedPath($condition['left'] ?? null) : null;

        if (null === $watched) {
            return [];
        }

        $iterator = LoopNodeHandler::iteratorNameOf($config);

        // loop_end advances the iterator, so a condition on it does change.
        if ('flow.' . $iterator === $watched) {
            return [];
        }

        $body = $this->body($loopId, $nodeMap, $edges);

        if ([] === $body) {
            return [];
        }

        foreach ($body as $bodyId) {
            $node = $nodeMap[$bodyId];
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';

            // A Solution node may write anything; a template node's inner writes are not expanded before publish.
            if ('template' === $type || ('' !== $type && null !== $this->registry->ownerOf($type))) {
                return [];
            }

            foreach ($this->writes->paths($node) as $written) {
                // Writing `contact.photos` changes `contact.photos.length`, writing `flow.resp` changes `flow.resp.status`.
                if ($watched === $written || str_starts_with($watched, $written . '.')) {
                    return [];
                }
            }
        }

        return [new FlowValidationErrorDto(
            path: "{$path}.config.condition",
            code: self::CODE_WHILE_UNCHANGED,
            message: "No node inside the loop changes '{$watched}', so its condition never changes and the loop may run "
                . 'until the iteration limit stops it. Ignore this if the value changes from outside the flow.',
        )];
    }

    /**
     * The state path a while condition reads, when it is a path this check can follow: a session or contact variable.
     *
     * @param  mixed  $left
     */
    private function watchedPath(mixed $left): ?string
    {
        if (is_string($left)) {
            return 1 === preg_match('/^(flow|contact)(\.\w+)+$/', $left) ? $left : null;
        }

        if (! is_array($left)) {
            return null;
        }

        if ('user_variable' === ($left['ref'] ?? null)) {
            $variable = is_array($left['variable'] ?? null)
                ? $left['variable']
                : ['name' => $left['name'] ?? null, 'storage' => $left['storage'] ?? null, 'group' => $left['group'] ?? null];

            return $this->writes->variablePath($variable);
        }

        if ('source' === ($left['ref'] ?? null) && is_string($left['source'] ?? null)) {
            $field = is_string($left['field'] ?? null) ? $left['field'] : ($left['path'] ?? null);

            if (is_string($field) && '' !== $field && in_array($left['source'], ['flow', 'contact'], true)) {
                return "{$left['source']}.{$field}";
            }
        }

        return null;
    }

    /**
     * Ids of the nodes reachable from the loop's `loop` handle up to its own `loop_end`.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     * @param  array<int, array<string, mixed>>     $edges
     *
     * @return list<string>
     */
    private function body(string $loopId, array $nodeMap, array $edges): array
    {
        $next = [];

        foreach ($edges as $edge) {
            if (is_string($edge['from'] ?? null) && is_string($edge['to'] ?? null)) {
                $next[$edge['from']][] = ['to' => $edge['to'], 'handle' => $edge['handle'] ?? 'default'];
            }
        }

        $queue = [];

        foreach ($next[$loopId] ?? [] as $edge) {
            if ('loop' === $edge['handle']) {
                $queue[] = $edge['to'];
            }
        }

        $seen = [];
        $body = [];

        while ([] !== $queue) {
            $current = array_shift($queue);

            if (isset($seen[$current]) || $current === $loopId || ! isset($nodeMap[$current])) {
                continue;
            }

            $seen[$current] = true;
            $node           = $nodeMap[$current];

            if (LoopEndNodeHandler::TYPE === ($node['type'] ?? null)) {
                continue;
            }

            $body[] = $current;

            foreach ($next[$current] ?? [] as $edge) {
                $queue[] = $edge['to'];
            }
        }

        return $body;
    }

    /**
     * Session variable name => ids of the nodes that write it, over every node but the loops themselves.
     *
     * @param  array<string, array<string, mixed>>  $nodeMap
     *
     * @return array<string, list<string>>
     */
    private function sessionWrites(array $nodeMap): array
    {
        $writes = [];

        foreach ($nodeMap as $nodeId => $node) {
            foreach ($this->writes->paths($node) as $path) {
                if (str_starts_with($path, 'flow.')) {
                    $writes[mb_substr($path, 5)][] = (string) $nodeId;
                }
            }
        }

        return $writes;
    }
}
