<?php

declare(strict_types=1);

namespace App\Domains\Flow\History;

use App\Domains\Flow\Models\FlowDefinition;

/**
 * Selects {@see DefaultHistoryWriter} or {@see NoOpHistoryWriter} based on
 * the running flow_definition's logging_enabled flag.
 *
 * Engine resolves a writer per-execution at the start of node execution and
 * passes it to instrumentation points (state_change emission after applying
 * stateChanges, ContactWriter notifications, lifecycle events). Plugin code
 * never instantiates this factory directly.
 */
final readonly class HistoryWriterFactory
{
    public function __construct(
        private DefaultHistoryWriter $default,
        private NoOpHistoryWriter $noOp,
    ) {
    }

    public function for(FlowDefinition $definition): HistoryWriterInterface
    {
        return $definition->logging_enabled
            ? $this->default
            : $this->noOp;
    }
}
