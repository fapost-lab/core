<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Enums\BranchOperator;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Exceptions\UnknownDataAccessorNamespacePrefixException;
use App\Domains\Flow\State\Variables\Variable;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Enums\StateNamespace;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\ObjectArrayField;
use FAPost\Support\Builder\Schema\Fields\SelectField;
use FAPost\Support\Builder\Schema\Fields\StatePickerField;
use FAPost\Support\Builder\Schema\Fields\TextareaField;
use FAPost\Support\Builder\Schema\Fields\TextField;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;
use InvalidArgumentException;

/**
 * Branch (Condition) node — evaluates a list of rules against operand values
 * and selects an output handle. Supports two operand formats per rule:
 *  - Structured `left` block (`user_variable` or `source`) — written by the
 *    new operand picker UI;
 *  - Legacy top-level `check` path (fallback when a rule has no `left`).
 *
 * `module.*` operands are resolved through {@see DataAccessorRegistryInterface};
 * `user_variable` operands are resolved through {@see VariableResolverInterface}.
 */
final class BranchNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "branch";

    private const string EXPRESSION_META = "expression";

    public function __construct(
        private readonly DataAccessorRegistryInterface $accessors,
        private readonly VariableResolverInterface $variableResolver,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Logic';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return Schema::make()
            ->section(
                Section::make('condition', 'Condition')
                    ->icon('adjustments-horizontal')
                    ->fields([
                        StatePickerField::make('check')
                            ->label('Check')
                            ->placeholder('flow.status'),
                        ObjectArrayField::make('rules')
                            ->label('Rules')
                            ->required()
                            ->itemLabel('{handle}')
                            ->itemFields([
                                TextField::make('handle')
                                    ->label('Handle')
                                    ->default('yes'),
                                SelectField::make('operator')
                                    ->label('Operator')
                                    ->options(BranchOperator::cases())
                                    ->default(BranchOperator::Eq),
                                TextareaField::make('value')
                                    ->label('Value'),
                            ]),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config      = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $defaultPath = is_string($config['check'] ?? null) && '' !== $config['check'] ? $config['check'] : null;
        $rules       = is_array($config['rules'] ?? null) ? $config['rules'] : [];

        [$handle, $matchedRule, $resolved] = $this->evaluateRules($rules, $defaultPath, $state, $context);

        if (null === $matchedRule && null !== $defaultPath && [] === $resolved) {
            // Pre-compute default check value for logging when no rule matched
            // and rules used legacy shape — keeps log parity with v1 behavior.
            $resolved[$defaultPath] = $this->resolveLegacyPath($defaultPath, $state, $context);
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $handle,
            logResolved: $resolved,
            metadata: [
                self::EXPRESSION_META => [
                    'operand'  => $matchedRule['__operand_path'] ?? $defaultPath,
                    'operator' => is_array($matchedRule) ? ($matchedRule['operator'] ?? null) : null,
                    'expected' => is_array($matchedRule) ? ($matchedRule['value'] ?? null) : null,
                ],
            ],
        );
    }

    /**
     * @param  array<int, mixed>     $rules
     * @param  array<string, mixed>  $state
     *
     * @return array{0: string, 1: array<string, mixed>|null, 2: array<string, mixed>}
     */
    private function evaluateRules(
        array $rules,
        ?string $defaultPath,
        array $state,
        NodeExecutionContext $context,
    ): array {
        $resolved = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            [$path, $value] = $this->resolveRuleOperand($rule, $defaultPath, $state, $context);

            if (null !== $path) {
                $resolved[$path] = $value;
            }

            if ($this->matchesRule($value, $rule)) {
                $handle = is_string($rule['handle'] ?? null) ? $rule['handle'] : 'default';

                $matched                   = $rule;
                $matched['__operand_path'] = $path;

                return [$handle, $matched, $resolved];
            }
        }

        return ['default', null, $resolved];
    }

    /**
     * Resolve a rule's left operand. Returns [path-for-logging, value].
     *
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $state
     *
     * @return array{0: string|null, 1: mixed}
     */
    private function resolveRuleOperand(
        array $rule,
        ?string $defaultPath,
        array $state,
        NodeExecutionContext $context,
    ): array {
        $left = $rule['left'] ?? null;

        // New structured format: { ref: 'user_variable' | 'source', ... }
        if (is_array($left)) {
            $ref = $left['ref'] ?? null;

            if ('user_variable' === $ref) {
                return $this->resolveUserVariableLeft($left, $state, $context);
            }

            if ('source' === $ref) {
                return $this->resolveSourceLeft($left, $state, $context);
            }

            throw new InvalidNodeConfigException("branch: unknown rule.left.ref '{$ref}'");
        }

        // Per-rule legacy string path
        if (is_string($left) && '' !== $left) {
            return [$left, $this->resolveLegacyPath($left, $state, $context)];
        }

        // Fallback to node-level `check`
        if (null !== $defaultPath) {
            return [$defaultPath, $this->resolveLegacyPath($defaultPath, $state, $context)];
        }

        throw new InvalidNodeConfigException('branch: rule must define left operand or node must define check');
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $state
     *
     * @return array{0: string, 1: mixed}
     */
    private function resolveUserVariableLeft(array $left, array $state, NodeExecutionContext $context): array
    {
        $variableConfig = $left['variable'] ?? null;

        if (!is_array($variableConfig)) {
            // Compact form: name embedded directly on left for forward-compat.
            $variableConfig = [
                'name'    => $left['name'] ?? null,
                'storage' => $left['storage'] ?? null,
                'group'   => $left['group'] ?? null,
            ];
        }

        try {
            $variable = Variable::tryFromArray($variableConfig);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidNodeConfigException(
                'branch: invalid user_variable left — ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if (!$variable instanceof Variable) {
            throw new InvalidNodeConfigException('branch: user_variable left missing required name/storage');
        }

        $path = $this->variableResolver->resolveTargetPath($variable);

        // Read through VariableResolver so coercion (type from schema registry /
        // variable declaration) is applied. Falls back to data_get when no reader
        // is available (legacy test contexts without a stateReader).
        $value = null !== $context->stateReader
            ? $this->variableResolver->read($variable, $context)
            : data_get($state, $path);

        return [$path, $value];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $state
     *
     * @return array{0: string, 1: mixed}
     */
    private function resolveSourceLeft(array $left, array $state, NodeExecutionContext $context): array
    {
        $source = is_string($left['source'] ?? null) ? $left['source'] : null;
        $field  = is_string($left['field'] ?? null) ? $left['field'] : null;

        if (null === $source || '' === $source) {
            throw new InvalidNodeConfigException('branch: source left requires non-empty source');
        }

        if (null === $field || '' === $field) {
            throw new InvalidNodeConfigException('branch: source left requires non-empty field');
        }

        // Module sources arrive as source='module.{name}' (preserves prefix
        // identity) — split into namespace + key for DataAccessor lookup.
        if (str_starts_with($source, 'module.')) {
            $path = "{$source}.{$field}";

            return [$path, $this->resolveLegacyPath($path, $state, $context)];
        }

        $path = "{$source}.{$field}";

        return [$path, $this->resolveLegacyPath($path, $state, $context)];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function resolveLegacyPath(string $path, array $state, NodeExecutionContext $context): mixed
    {
        if (str_starts_with($path, 'module.')) {
            [$prefix, $key] = $this->splitModulePath($path);
            try {
                $accessor = $this->accessors->resolve($prefix);
            } catch (UnknownDataAccessorNamespacePrefixException $exception) {
                throw new InvalidNodeConfigException($exception->getMessage(), previous: $exception);
            }

            return $accessor->get($key, $context->contactId, $context->tenantId);
        }

        if (
            str_starts_with($path, StateNamespace::Flow->value . '.')
            || str_starts_with($path, StateNamespace::System->value . '.')
            || str_starts_with($path, StateNamespace::Rag->value . '.')
            || str_starts_with($path, 'contact.')
            || str_starts_with($path, 'call.')
        ) {
            return data_get($state, $path);
        }

        throw new InvalidNodeConfigException("branch: unsupported namespace in path '{$path}'");
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitModulePath(string $path): array
    {
        $segments = explode('.', $path, 4);

        if (count($segments) < 3 || 'module' !== $segments[0] || '' === $segments[1]) {
            throw new InvalidNodeConfigException("branch: invalid module path '{$path}'");
        }

        $prefix = "{$segments[0]}.{$segments[1]}";
        $key    = implode('.', array_slice($segments, 2));

        if ('' === $key) {
            throw new InvalidNodeConfigException("branch: invalid module path '{$path}'");
        }

        return [$prefix, $key];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function matchesRule(mixed $value, array $rule): bool
    {
        $rawOperator = is_string($rule['operator'] ?? null) ? $rule['operator'] : null;
        $operator    = null !== $rawOperator ? BranchOperator::tryFrom($rawOperator) : null;

        if (null === $operator) {
            return false;
        }

        return match ($operator) {
            BranchOperator::Eq       => $value === ($rule['value'] ?? null),
            BranchOperator::Neq      => $value !== ($rule['value'] ?? null),
            BranchOperator::Gt       => $value > ($rule['value'] ?? null),
            BranchOperator::Gte      => $value >= ($rule['value'] ?? null),
            BranchOperator::Lt       => $value < ($rule['value'] ?? null),
            BranchOperator::Lte      => $value <= ($rule['value'] ?? null),
            BranchOperator::Contains => str_contains((string)$value, (string)($rule['value'] ?? '')),
            BranchOperator::In       => in_array($value, is_array($rule['value'] ?? null) ? $rule['value'] : [], true),
            BranchOperator::Empty    => empty($value),
            BranchOperator::NotEmpty => ! empty($value),
        };
    }
}
