<?php

declare(strict_types=1);

namespace App\Domains\Flow\Trace;

use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Inserts one node trace row into `flow_session_history`.
 *
 * Always — the trace does not depend on a definition's `logging_enabled`, which still gates `state_change` and
 * `subflow_*` rows. The insert runs in a transaction of its own: inside an outer transaction that is a savepoint, so a
 * failed insert rolls back only itself and never leaves the outer transaction aborted. A failure is logged as
 * `flow.trace.write_failed` without the envelope (it may hold what the trace masks) and is never rethrown: a node's
 * side effects have already happened, and a failed job would retry and repeat them.
 */
final readonly class NodeTraceWriter
{
    public function __construct(
        private ConnectionInterface $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function write(
        HistoryEventType $eventType,
        string $tenantId,
        string $sessionId,
        string $nodeId,
        array $envelope,
    ): void {
        try {
            $this->connection->transaction(static function () use ($eventType, $tenantId, $sessionId, $nodeId, $envelope): void {
                FlowSessionHistoryEntry::query()->create([
                    'tenant_id'  => $tenantId,
                    'session_id' => $sessionId,
                    'node_id'    => $nodeId,
                    'event_type' => $eventType,
                    'metadata'   => $envelope,
                    'created_at' => Carbon::now(),
                ]);
            });
        } catch (Throwable $exception) {
            $this->failed($eventType, $sessionId, $nodeId, $exception);
        }
    }

    public function failed(HistoryEventType $eventType, string $sessionId, string $nodeId, Throwable $exception): void
    {
        $this->logger->warning('flow.trace.write_failed', [
            'session_id' => $sessionId,
            'node_id'    => $nodeId,
            'event_type' => $eventType->value,
            'error'      => $exception::class,
        ]);
    }
}
