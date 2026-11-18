<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use Fapost\Foundation\Flow\Enums\StateNamespace;
use InvalidArgumentException;

/**
 * Reads a subflow node's `input_mapping` and `output_mapping` and moves values between parent and child session
 * variables. Both fields are optional maps keyed by a session variable name (`flow.<name>`): `input_mapping` maps a
 * child variable to an expression over the parent's state, `output_mapping` maps a parent variable to a child
 * variable. A node without them passes nothing either way (ADR-11, V1.1).
 */
final class SubflowMapping
{
    public const string INPUT = 'input_mapping';

    public const string OUTPUT = 'output_mapping';

    /**
     * The entries of one mapping field: an empty array when the field is absent or empty, null when it is present
     * but is not a map of name to value.
     *
     * @param  array<string, mixed>  $config
     *
     * @return array<array-key, mixed>|null
     */
    public static function entries(array $config, string $field): ?array
    {
        $raw = $config[$field] ?? null;

        if (null === $raw || [] === $raw) {
            return [];
        }

        if (! is_array($raw) || array_is_list($raw)) {
            return null;
        }

        return $raw;
    }

    /**
     * Whether a value names a session variable: an identifier that is not reserved (the backend variable contract).
     */
    public static function isVariableName(mixed $name): bool
    {
        if (! is_string($name) || '' === $name) {
            return false;
        }

        try {
            new Variable($name, VariableStorage::Session);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /**
     * The parent's variables the node's `output_mapping` takes from a child's final state. A mapped child variable
     * the child never set comes back as null, as a `call` node's `result_mapping` does; an entry with an invalid name
     * is skipped (validation refuses it at publish).
     *
     * @param  array<string, mixed>  $config      the subflow node's config
     * @param  array<string, mixed>  $childState  the child session's state
     *
     * @return array<string, mixed>  parent variable name => value
     */
    public static function outputs(array $config, array $childState): array
    {
        $childVariables = is_array($childState[StateNamespace::Flow->value] ?? null)
            ? $childState[StateNamespace::Flow->value]
            : [];

        $outputs = [];

        foreach (self::entries($config, self::OUTPUT) ?? [] as $target => $source) {
            $target = (string) $target;

            if (! self::isVariableName($target) || ! self::isVariableName($source)) {
                continue;
            }

            $outputs[$target] = $childVariables[$source] ?? null;
        }

        return $outputs;
    }

    /**
     * A session state with the given session variables set, each replacing its previous value as a whole.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $variables  variable name => value
     *
     * @return array<string, mixed>
     */
    public static function withSessionVariables(array $state, array $variables): array
    {
        $namespace = StateNamespace::Flow->value;
        $current   = is_array($state[$namespace] ?? null) ? $state[$namespace] : [];

        foreach ($variables as $name => $value) {
            $current[$name] = $value;
        }

        $state[$namespace] = $current;

        return $state;
    }
}
