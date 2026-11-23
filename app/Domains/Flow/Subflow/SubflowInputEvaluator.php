<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use Fapost\Foundation\DTO\NodeExecutionContext;

/**
 * Evaluates a node's `input_mapping` ({@see SubflowMapping::INPUT}) against the running flow's state: the starting
 * session variables of the flow the node starts — a `subflow` child, or the conversation a `start_flow` node starts
 * for a contact.
 *
 * A value that is exactly one placeholder passes the value itself (an array stays an array, a number a number);
 * anything else is rendered as text.
 */
final readonly class SubflowInputEvaluator
{
    /** An input expression that is exactly one placeholder passes the value itself, not its text. */
    private const string SINGLE_PLACEHOLDER_PATTERN = '/^\s*\{\{\s*([\w.]+)\s*\}\}\s*$/';

    public function __construct(
        private TemplateRenderer $templates,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config    the node's config
     * @param  array<string, mixed>  $state
     * @param  string                $nodeType  named in the error of a malformed mapping
     *
     * @return array<string, mixed>  variable name => value
     *
     * @throws InvalidNodeConfigException when the mapping is not a map of variable names to strings
     */
    public function evaluate(array $config, array $state, NodeExecutionContext $context, string $nodeType): array
    {
        $entries = SubflowMapping::entries($config, SubflowMapping::INPUT);

        if (null === $entries) {
            throw new InvalidNodeConfigException("{$nodeType}: input_mapping must map variable names to expressions");
        }

        $input = [];

        foreach ($entries as $name => $expression) {
            $name = (string) $name;

            if (! SubflowMapping::isVariableName($name)) {
                throw new InvalidNodeConfigException("{$nodeType}: invalid input_mapping variable name '{$name}'");
            }

            if (! is_string($expression)) {
                throw new InvalidNodeConfigException("{$nodeType}: input_mapping value for '{$name}' must be a string");
            }

            $input[$name] = $this->evaluateExpression($expression, $state, $context);
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function evaluateExpression(string $expression, array $state, NodeExecutionContext $context): mixed
    {
        if (1 === preg_match(self::SINGLE_PLACEHOLDER_PATTERN, $expression, $match)) {
            return null !== $context->stateReader
                ? $context->stateReader->read($match[1])
                : data_get($state, $match[1]);
        }

        return $this->templates->render($expression, $context, $state);
    }
}
