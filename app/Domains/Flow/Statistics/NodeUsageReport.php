<?php

declare(strict_types=1);

namespace App\Domains\Flow\Statistics;

use Carbon\CarbonImmutable;

/**
 * One tenant's answer to "is this node type still in use, and is it healthy?".
 */
final readonly class NodeUsageReport
{
    /**
     * @param  list<NodeTypeUsage>  $usages              ordered by type, then version
     * @param  list<string>         $unusedHandlerTypes  registered types with no usage at all
     */
    public function __construct(
        /** Start of the runtime window; `flow_logs` retention caps how far back it can reach. */
        public CarbonImmutable $runtimeSince,
        public array $usages,
        public array $unusedHandlerTypes,
    ) {
    }

    /**
     * Nodes sitting in active definitions with no handler registered for their
     * version — these flows fail as soon as execution reaches the node.
     *
     * @return list<NodeTypeUsage>
     */
    public function orphaned(): array
    {
        return array_values(array_filter(
            $this->usages,
            static fn (NodeTypeUsage $usage): bool => $usage->isOrphaned(),
        ));
    }
}
