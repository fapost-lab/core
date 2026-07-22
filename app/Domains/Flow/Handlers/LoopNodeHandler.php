<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\OperandResolver;
use App\Domains\Flow\Handlers\Support\OperatorComparator;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use RuntimeException;

/**
 * Loop node — evaluates a loop continuation condition and routes execution
 * either into the loop body (`loop` handle) or past the loop (`default` handle).
 *
 * Two modes:
 *  - **counted** — repeats N times (N from `count_source`); `flow.{iterator_name}_total` holds N.
 *  - **while**   — repeats while a condition evaluates to true.
 *
 * The paired {@see LoopEndNodeHandler} increments `flow.{iterator_name}` and the
 * engine special-cases its type to navigate back to this node without an edge.
 *
 * State written by this handler lives in the `flow.*` namespace, which is open
 * to all handlers ({@see SystemStateNamespacePolicy}).
 */
final class LoopNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'loop';

    /** Default iterator variable name when `iterator_name` is absent from config. */
    public const string DEFAULT_ITERATOR_NAME = 'iterator';

    /** Suffix appended to the iterator name for its per-loop total (counted mode). */
    public const string TOTAL_SUFFIX = '_total';

    private const string LOOP_HANDLE    = 'loop';
    private const string DEFAULT_HANDLE = 'default';

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

    public function label(): string
    {
        return 'Loop';
    }

    /**
     * The loop config UI is a bespoke Vue override (`LoopConfig.vue`); the schema
     * only seeds `default_config` so a freshly-inserted node starts in counted
     * mode with the default iterator name.
     *
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return [
            'default_config' => [
                'mode'          => 'counted',
                'iterator_name' => self::DEFAULT_ITERATOR_NAME,
            ],
        ];
    }

    /**
     * Evaluate the loop condition and return either the `loop` handle (body) or
     * `default` handle (exit), together with state mutations for the iterator.
     *
     * Idempotent: on re-entry after LoopEnd increments the iterator, this handler
     * simply re-evaluates the condition against the already-persisted state.
     */
    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config       = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $mode         = is_string($config['mode'] ?? null) ? $config['mode'] : 'counted';
        $iteratorName = $this->resolveIteratorName($config);

        $iteratorPath = 'flow.' . $iteratorName;
        // Total is per-loop, derived from the iterator name so sibling loops
        // never collide (e.g. iterator → iterator_total, items → items_total).
        $totalPath = 'flow.' . $iteratorName . self::TOTAL_SUFFIX;

        // Strict null check per spec §3.6 — persister stores null on cleanup.
        $currentIterator = data_get($state, $iteratorPath);
        $stateChanges    = [];

        if (null === $currentIterator) {
            $stateChanges[$iteratorPath] = 1;
            $currentIterator             = 1;
        }

        return 'counted' === $mode
            ? $this->evaluateCounted($config, $state, $context, $iteratorPath, $totalPath, (int)$currentIterator, $stateChanges)
            : $this->evaluateWhile($config, $state, $context, $iteratorPath, (int)$currentIterator, $stateChanges);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $stateChanges  Already-accumulated iterator-init change (if any).
     */
    private function evaluateCounted(
        array $config,
        array $state,
        NodeExecutionContext $context,
        string $iteratorPath,
        string $totalPath,
        int $currentIterator,
        array $stateChanges,
    ): NodeExecutionResult {
        $countSource = $config['count_source'] ?? null;

        if (!is_array($countSource)) {
            throw new InvalidNodeConfigException('loop: counted mode requires a count_source operand.');
        }

        // 'literal' type is a loop-native format; all other shapes go through OperandResolver.
        if ('literal' === ($countSource['type'] ?? null)) {
            $total = $countSource['value'] ?? null;
        } else {
            [, $total] = $this->operands->resolve(['left' => $countSource], null, $state, $context);
        }

        if (!is_numeric($total)) {
            throw new RuntimeException(
                sprintf('loop: count_source resolved to non-numeric value (%s). Session failed.', gettype($total)),
            );
        }

        $totalInt = (int)$total;

        // Re-write the total every pass so templates always see the current value.
        $stateChanges[$totalPath] = $totalInt;

        if ($currentIterator <= $totalInt) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: self::LOOP_HANDLE,
                stateChanges: $stateChanges,
            );
        }

        // Exit — cleanup iterator state so sequential loops with the same name work.
        $stateChanges[$iteratorPath] = null;
        $stateChanges[$totalPath]    = null;

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: self::DEFAULT_HANDLE,
            stateChanges: $stateChanges,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $stateChanges
     */
    private function evaluateWhile(
        array $config,
        array $state,
        NodeExecutionContext $context,
        string $iteratorPath,
        int $currentIterator,
        array $stateChanges,
    ): NodeExecutionResult {
        $condition = $config['condition'] ?? null;

        if (!is_array($condition)) {
            throw new InvalidNodeConfigException('loop: while mode requires a condition block.');
        }

        [, $leftValue] = $this->operands->resolve($condition, null, $state, $context);

        $continues = $this->comparator->matches(
            $leftValue,
            is_string($condition['operator'] ?? null) ? $condition['operator'] : null,
            $condition['value'] ?? null,
        );

        if ($continues) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: self::LOOP_HANDLE,
                stateChanges: $stateChanges,
            );
        }

        // Exit — cleanup.
        $stateChanges[$iteratorPath] = null;

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: self::DEFAULT_HANDLE,
            stateChanges: $stateChanges,
        );
    }

    /** @param  array<string, mixed>  $config */
    private function resolveIteratorName(array $config): string
    {
        $raw = $config['iterator_name'] ?? null;

        return is_string($raw) && '' !== $raw ? $raw : self::DEFAULT_ITERATOR_NAME;
    }
}
