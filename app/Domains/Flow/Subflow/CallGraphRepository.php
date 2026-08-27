<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use Illuminate\Database\ConnectionInterface;

/**
 * Read/write surface over the `flow_callgraph_edges` reverse-index table.
 * Writes are tied to a flow_definition publish (atomic delete-then-insert
 * for that caller_definition_id); reads are used by the cycle/depth
 * validator and by future call-graph visualization.
 */
final readonly class CallGraphRepository
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * Replace all edges originating from a specific caller_definition_id.
     * Used at publish time: drop all edges from previous active version,
     * insert edges from the new version's subflow nodes.
     *
     * @param  list<string>  $calleeFlowIds  Distinct callee flow ids referenced.
     */
    public function replaceForCallerDefinition(
        string $callerFlowId,
        string $callerDefinitionId,
        array $calleeFlowIds,
    ): void {
        $this->connection->table('flow_callgraph_edges')
            ->where('caller_definition_id', $callerDefinitionId)
            ->delete();

        if ([] === $calleeFlowIds) {
            return;
        }

        $rows = [];
        foreach (array_unique($calleeFlowIds) as $calleeFlowId) {
            $rows[] = [
                'caller_flow_id'       => $callerFlowId,
                'callee_flow_id'       => $calleeFlowId,
                'caller_definition_id' => $callerDefinitionId,
            ];
        }

        $this->connection->table('flow_callgraph_edges')->insert($rows);
    }

    /**
     * Direct callees of a given flow_id across all of its definitions.
     *
     * @return list<string>
     */
    public function calleesOf(string $callerFlowId): array
    {
        return $this->connection->table('flow_callgraph_edges')
            ->where('caller_flow_id', $callerFlowId)
            ->pluck('callee_flow_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Direct callers of a given flow_id (anyone who has at least one subflow
     * node pointing to it).
     *
     * @return list<string>
     */
    public function callersOf(string $calleeFlowId): array
    {
        return $this->connection->table('flow_callgraph_edges')
            ->where('callee_flow_id', $calleeFlowId)
            ->pluck('caller_flow_id')
            ->unique()
            ->values()
            ->all();
    }
}
