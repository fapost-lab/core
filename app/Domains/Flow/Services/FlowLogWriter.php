<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Models\FlowSession;
use FAPost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use JsonException;

/**
 * Persists raw flow execution logs. Two row kinds are expected:
 *
 * — Execution: {@see write()} — one row per node execution (handler outcome, resolved stateChanges).
 * — Lifecycle: {@see writeFlowStart()} / {@see writeFlowEnd()} — session-level markers (status flow_start / flow_end).
 *
 * When a terminal node ends the run, both an execution row and a {@code flow_end} row may appear in the same
 * transaction; analytics should treat {@code flow_end} as lifecycle, node rows as execution.
 */
final class FlowLogWriter
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * @param  array<string, mixed>  $node
     *
     * @throws JsonException
     */
    public function write(
        FlowSession $session,
        array $node,
        NodeExecutionResult $result,
        ?string $nextNodeId,
    ): void {
        $now = now();

        $this->connection->table('flow_logs')->insert([
            'id'            => Str::ulid()->toRfc4122(),
            'session_id'    => $session->getKey(),
            'node_id'       => $node['id'] ?? null,
            'node_type'     => isset($node['type']) && is_string($node['type']) ? $node['type'] : null,
            'node_version'  => isset($node['version']) && is_int($node['version']) ? $node['version'] : 1,
            'status'        => $result->status->value,
            'source_handle' => $result->sourceHandle,
            'next_node_id'  => $nextNodeId,
            'resolved'      => $this->encodeJson($result->stateChanges),
            'error'         => $result->errorMessage,
            'metadata'      => $this->encodeJson($result->metadata),
            'created_at'    => $now,
        ]);
    }

    /**
     * @throws JsonException
     */
    public function writeFlowStart(FlowSession $session): void
    {
        $now = now();

        $this->connection->table('flow_logs')->insert([
            'id'            => Str::ulid()->toRfc4122(),
            'session_id'    => $session->getKey(),
            'node_id'       => null,
            'node_type'     => null,
            'node_version'  => 1,
            'status'        => 'flow_start',
            'source_handle' => null,
            'next_node_id'  => null,
            'resolved'      => $this->encodeJson([]),
            'error'         => null,
            'metadata'      => $this->encodeJson([]),
            'created_at'    => $now,
        ]);
    }

    /**
     * @throws JsonException
     */
    public function writeFlowEnd(FlowSession $session, string $reason): void
    {
        $now = now();

        $this->connection->table('flow_logs')->insert([
            'id'            => Str::ulid()->toRfc4122(),
            'session_id'    => $session->getKey(),
            'node_id'       => null,
            'node_type'     => null,
            'node_version'  => 1,
            'status'        => 'flow_end',
            'source_handle' => null,
            'next_node_id'  => null,
            'resolved'      => $this->encodeJson([]),
            'error'         => null,
            'metadata'      => $this->encodeJson(['reason' => $reason]),
            'created_at'    => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
