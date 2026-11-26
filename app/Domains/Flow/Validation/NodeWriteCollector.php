<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Enums\AssignTarget;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use InvalidArgumentException;

/**
 * Reads the state paths a node writes, as dotted strings (`flow.x`, `contact.group.x`), from every config shape of the
 * nodes that save a value: `input`, `assign` (operations and the legacy `target` / `key` form), `send_message`
 * (`save_to_variable`, legacy `save_to`), `ask_person` (`save_to_variable`), `call` (`save_to_variable`,
 * `save_response_to`, `result_mapping`) and `subflow` (`output_mapping` keys). A `loop` node's own counter is not here: it belongs to the loop, and each checker decides
 * how to treat it. Shared by {@see LoopConfigChecker} and {@see VariablePathConflictChecker}.
 *
 * Which node types save a variable, and under which config key, is not declared in one place yet. A node type that
 * gains or changes a save target must be checked against every place that lists them: this class,
 * {@see \App\Domains\Flow\Services\VariableSchemaCollector} (publish-time type conflicts and `tenant_variable_schema`),
 * {@see FlowGraphStructureValidator::variableContractErrors()} (the variable contract),
 * `resources/js/builder/composables/useFlowVariables.ts` (the builder's variable picker), the docblocks of
 * {@see LoopConfigChecker} and {@see VariablePathConflictChecker}, `docs/reference/specs/flow-engine/06-validation.md`
 * (`variable_path_conflict`, the no-name rule and the loop writes) and
 * `docs/reference/specs/builder/storage/10-variable-type-coercion.md` (what publish collects into the schema).
 */
final readonly class NodeWriteCollector
{
    /**
     * State paths a node saves a value to, from the config shapes of the nodes that declare variables.
     *
     * @param  array<string, mixed>  $node
     *
     * @return list<string>
     */
    public function paths(array $node): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $paths  = [];

        $add = function (mixed $raw) use (&$paths): void {
            $path = is_array($raw) ? $this->variablePath($raw) : null;

            if (null !== $path) {
                $paths[] = $path;
            }
        };

        switch ($node['type'] ?? null) {
            case 'input':
                $add($config['variable'] ?? null);

                if (is_string($config['save_to'] ?? null) && 1 === preg_match('/^(flow|contact)(\.\w+)+$/', $config['save_to'])) {
                    $paths[] = $config['save_to'];
                } elseif (is_string($config['save_to'] ?? null) && 1 === preg_match('/^\w+$/', $config['save_to'])) {
                    $paths[] = 'flow.' . $config['save_to'];
                }

                break;
            case 'assign':
                foreach (is_array($config['operations'] ?? null) ? $config['operations'] : [] as $operation) {
                    $add(is_array($operation) ? ($operation['variable'] ?? null) : null);
                }

                if (is_string($config['key'] ?? null) && '' !== $config['key']) {
                    $paths[] = (AssignTarget::Contact->value === ($config['target'] ?? null) ? 'contact.' : 'flow.') . $config['key'];
                }

                break;
            case 'send_message':
                $add($config['save_to_variable'] ?? null);
                $paths = [...$paths, ...$this->legacyPath($config['save_to'] ?? null)];

                break;
            case 'ask_person':
                $add($config['save_to_variable'] ?? null);

                break;
            case 'call':
                $add($config['save_to_variable'] ?? null);
                $paths = [...$paths, ...$this->legacyPath($config['save_response_to'] ?? null)];

                foreach (is_array($config['result_mapping'] ?? null) ? $config['result_mapping'] : [] as $mapping) {
                    $add(is_array($mapping) ? ($mapping['to'] ?? null) : null);
                }

                break;
            case 'subflow':
                // The output mapping copies child variables into this flow's session: `{here: child}`.
                foreach (is_array($config['output_mapping'] ?? null) ? array_keys($config['output_mapping']) : [] as $name) {
                    if (is_string($name) && '' !== $name) {
                        $paths[] = 'flow.' . $name;
                    }
                }

                break;
            default:
                break;
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $raw  a variable as the builder stores it
     */
    public function variablePath(array $raw): ?string
    {
        try {
            $variable = Variable::tryFromArray($raw);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (! $variable instanceof Variable) {
            return null;
        }

        if (VariableStorage::Session === $variable->storage) {
            return 'flow.' . $variable->name;
        }

        return null === $variable->group ? "contact.{$variable->name}" : "contact.{$variable->group}.{$variable->name}";
    }

    /**
     * A legacy `save_to` string: `flow.x`, `contact.x` or a bare name (a session variable).
     *
     * @return list<string>
     */
    private function legacyPath(mixed $raw): array
    {
        if (! is_string($raw)) {
            return [];
        }

        if (1 === preg_match('/^(flow|contact)(\.\w+)+$/', $raw)) {
            return [$raw];
        }

        return 1 === preg_match('/^\w+$/', $raw) ? ['flow.' . $raw] : [];
    }
}
