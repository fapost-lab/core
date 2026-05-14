<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableType;
use InvalidArgumentException;

/**
 * Scans a published flow's node list and extracts all variable declarations.
 *
 * Understands the three node types that declare variables:
 *  - `input`        → config.variable
 *  - `assign`       → config.operations[*].variable
 *  - `send_message` → config.save_to_variable
 *
 * Returns Variable instances enriched with the declaring node_id so
 * PublishFlowService can record provenance in tenant_variable_schema.
 */
final class VariableSchemaCollector
{
    /**
     * @param  array<int, array<string, mixed>>  $nodes
     *
     * @return list<array{variable: Variable, node_id: string}>
     */
    public function collect(array $nodes): array
    {
        $declarations = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $nodeId = is_string($node['id'] ?? null) ? $node['id'] : null;

            if (null === $nodeId || '' === $nodeId) {
                continue;
            }

            $type   = is_string($node['type'] ?? null) ? $node['type'] : '';
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            $found = match ($type) {
                'input'        => $this->collectFromInput($config),
                'assign'       => $this->collectFromAssign($config),
                'send_message' => $this->collectFromSendMessage($config),
                default        => [],
            };

            foreach ($found as $variable) {
                $declarations[] = ['variable' => $variable, 'node_id' => $nodeId];
            }
        }

        return $declarations;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<Variable>
     */
    private function collectFromInput(array $config): array
    {
        $raw = $config['variable'] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        $variable = $this->tryFromArray($raw);

        return null !== $variable ? [$variable] : [];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function tryFromArray(array $raw): ?Variable
    {
        try {
            $variable = Variable::tryFromArray($raw);
        } catch (InvalidArgumentException) {
            return null;
        }

        if (!$variable instanceof Variable) {
            return null;
        }

        // Ensure variable has a declared type; fall back to text for legacy declarations.
        if (null === $variable->type) {
            $variable = new Variable(
                name: $variable->name,
                storage: $variable->storage,
                group: $variable->group,
                type: VariableType::Text,
            );
        }

        return $variable;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<Variable>
     */
    private function collectFromAssign(array $config): array
    {
        $operations = is_array($config['operations'] ?? null) ? $config['operations'] : [];
        $variables  = [];

        foreach ($operations as $op) {
            if (!is_array($op)) {
                continue;
            }

            $raw = $op['variable'] ?? null;

            if (!is_array($raw)) {
                continue;
            }

            $variable = $this->tryFromArray($raw);

            if (null !== $variable) {
                $variables[] = $variable;
            }
        }

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<Variable>
     */
    private function collectFromSendMessage(array $config): array
    {
        $raw = $config['save_to_variable'] ?? null;

        if (!is_array($raw)) {
            return [];
        }

        $variable = $this->tryFromArray($raw);

        return null !== $variable ? [$variable] : [];
    }
}
