<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use Fapost\Foundation\Flow\DTO\NodeConfigIssue;

/**
 * Checks the names in a subflow node's `input_mapping` and `output_mapping` for validate and publish. Pure: it does
 * not look at the child flow, whose latest active definition is only known when the child starts.
 */
final class SubflowMappingValidator
{
    /**
     * @param  array<string, mixed>  $config  the subflow node's config
     *
     * @return list<NodeConfigIssue>
     */
    public function validate(array $config): array
    {
        return [...$this->validateInput($config), ...$this->validateOutput($config)];
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<NodeConfigIssue>
     */
    private function validateInput(array $config): array
    {
        $entries = SubflowMapping::entries($config, SubflowMapping::INPUT);

        if (null === $entries) {
            return [NodeConfigIssue::error(
                'subflow_invalid_input_mapping',
                'input_mapping must map child variable names to expressions.',
                SubflowMapping::INPUT,
            )];
        }

        $issues = [];

        foreach ($entries as $name => $expression) {
            $name  = (string) $name;
            $field = SubflowMapping::INPUT . ".{$name}";

            if (! SubflowMapping::isVariableName($name)) {
                $issues[] = NodeConfigIssue::error(
                    'subflow_invalid_input_name',
                    "input_mapping: '{$name}' is not a valid variable name for the child flow.",
                    $field,
                );
            }

            if (! is_string($expression)) {
                $issues[] = NodeConfigIssue::error(
                    'subflow_invalid_input_value',
                    "input_mapping: the value for '{$name}' must be an expression or a literal text.",
                    $field,
                );
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<NodeConfigIssue>
     */
    private function validateOutput(array $config): array
    {
        $entries = SubflowMapping::entries($config, SubflowMapping::OUTPUT);

        if (null === $entries) {
            return [NodeConfigIssue::error(
                'subflow_invalid_output_mapping',
                'output_mapping must map parent variable names to child variable names.',
                SubflowMapping::OUTPUT,
            )];
        }

        $issues = [];

        foreach ($entries as $name => $source) {
            $name  = (string) $name;
            $field = SubflowMapping::OUTPUT . ".{$name}";

            if (! SubflowMapping::isVariableName($name)) {
                $issues[] = NodeConfigIssue::error(
                    'subflow_invalid_output_name',
                    "output_mapping: '{$name}' is not a valid variable name for this flow.",
                    $field,
                );
            }

            if (! SubflowMapping::isVariableName($source)) {
                $shown    = is_scalar($source) ? (string) $source : get_debug_type($source);
                $issues[] = NodeConfigIssue::error(
                    'subflow_invalid_output_source',
                    "output_mapping: '{$shown}' is not a valid variable name of the child flow.",
                    $field,
                );
            }
        }

        return $issues;
    }
}
