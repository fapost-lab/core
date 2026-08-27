<?php

declare(strict_types=1);

namespace App\Domains\Flow\History;

use FAPost\Foundation\Flow\History\HistoryEventType;

/**
 * Absorbs all history record() calls without persisting anything.
 *
 * Returned by {@see HistoryWriterFactory} when the running flow_definition
 * has logging_enabled = false. Lets engine and ContactWriter call the writer
 * unconditionally without branching on the flag.
 */
final class NoOpHistoryWriter implements HistoryWriterInterface
{
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
    }
}
