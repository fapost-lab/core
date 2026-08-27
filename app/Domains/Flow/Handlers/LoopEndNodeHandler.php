<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;

/**
 * LoopEnd node — increments the iterator variable and signals the engine to
 * navigate back to the parent Loop node (engine-level special-case, no edge).
 *
 * The handler itself is graph-unaware. `iterator_name` is read from the node's
 * own config, where it was denormalized from the parent Loop node by
 * {@see \App\Domains\Flow\Services\PublishFlowService} at publish time.
 *
 * `loop_node_id` is also in config so the engine can resolve the back-link
 * without any DB lookup or graph traversal.
 *
 * Idempotent: the increment is persisted atomically with the navigation advance
 * under optimistic lock, so job retries cannot double-increment.
 */
final class LoopEndNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'loop_end';

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
        return 'Loop end';
    }

    /**
     * Auto-managed node — created, wired and removed by the builder alongside its
     * parent {@see LoopNodeHandler}. Hidden from the palette (see NodeTypesController)
     * and carries no user-editable config, so the schema is empty.
     *
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return [];
    }

    /**
     * Increment `flow.{iterator_name}` and return `Executed` with no source
     * handle — the engine special-cases this TYPE to navigate to `loop_node_id`.
     */
    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config       = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $iteratorName = is_string($config['iterator_name'] ?? null) && '' !== $config['iterator_name']
            ? $config['iterator_name']
            : LoopNodeHandler::DEFAULT_ITERATOR_NAME;

        $iteratorPath    = 'flow.' . $iteratorName;
        $currentIterator = data_get($state, $iteratorPath);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: null,
            stateChanges: [
                $iteratorPath => ((int)$currentIterator) + 1,
            ],
        );
    }
}
