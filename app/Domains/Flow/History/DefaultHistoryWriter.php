<?php

declare(strict_types=1);

namespace App\Domains\Flow\History;

use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists structured history events to flow_session_history.
 *
 * Failures are caught locally and logged as warnings — history is a secondary
 * concern; loss of a history record must not break handler execution. Loss of
 * the underlying state mutation is the engine's responsibility (separate
 * transaction in ContactWriter / atomic UPDATE in engine).
 */
final readonly class DefaultHistoryWriter implements HistoryWriterInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function record(
        HistoryEventType $eventType,
        string $tenantId,
        string $sessionId,
        string $nodeId,
        ?string $path = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        array $metadata = [],
    ): void {
        try {
            FlowSessionHistoryEntry::create([
                'tenant_id'  => $tenantId,
                'session_id' => $sessionId,
                'node_id'    => $nodeId,
                'event_type' => $eventType,
                'path'       => $path,
                'old_value'  => $this->normalize($oldValue),
                'new_value'  => $this->normalize($newValue),
                'metadata'   => [] === $metadata ? null : $metadata,
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('flow.history.write_failed', [
                'event_type' => $eventType->value,
                'session_id' => $sessionId,
                'node_id'    => $nodeId,
                'path'       => $path,
                'error'      => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Coerce arbitrary values into JSON-serialisable shape for jsonb columns.
     * Wraps scalars/null in array form for consistent column shape.
     *
     * @return array<int|string, mixed>|null
     */
    private function normalize(mixed $value): ?array
    {
        if (null === $value) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return ['value' => $value];
    }
}
