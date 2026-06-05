<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Enums\BranchOperator;
use App\Domains\Flow\Handlers\Support\OperandResolver;
use App\Domains\Flow\Handlers\Support\OperatorComparator;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\ObjectArrayField;
use FAPost\Support\Builder\Schema\Fields\SelectField;
use FAPost\Support\Builder\Schema\Fields\StatePickerField;
use FAPost\Support\Builder\Schema\Fields\TextareaField;
use FAPost\Support\Builder\Schema\Fields\TextField;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;

/**
 * Branch (Condition) node — evaluates a list of rules against operand values
 * and selects an output handle. Supports two operand formats per rule:
 *  - Structured `left` block (`user_variable` or `source`) — written by the
 *    new operand picker UI;
 *  - Legacy top-level `check` path (fallback when a rule has no `left`).
 *
 * Operand resolution ({@see OperandResolver}) and operator comparison
 * ({@see OperatorComparator}) are shared with other condition-style nodes so
 * the semantics live in one place.
 */
final class BranchNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "branch";

    private const string EXPRESSION_META = "expression";

    private readonly OperandResolver $operands;

    private readonly OperatorComparator $comparator;

    public function __construct(
        DataAccessorRegistryInterface $accessors,
        VariableResolverInterface $variableResolver,
    ) {
        $this->operands   = new OperandResolver($accessors, $variableResolver);
        $this->comparator = new OperatorComparator();
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
            $resolved[$defaultPath] = $this->operands->resolveLegacyPath($defaultPath, $state, $context);
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

            [$path, $value] = $this->operands->resolve($rule, $defaultPath, $state, $context);

            if (null !== $path) {
                $resolved[$path] = $value;
            }

            $operator = is_string($rule['operator'] ?? null) ? $rule['operator'] : null;

            if ($this->comparator->matches($value, $operator, $rule['value'] ?? null)) {
                $handle = is_string($rule['handle'] ?? null) ? $rule['handle'] : 'default';

                $matched                   = $rule;
                $matched['__operand_path'] = $path;

                return [$handle, $matched, $resolved];
            }
        }

        return ['default', null, $resolved];
    }
}
