<?php

declare(strict_types=1);

namespace App\Domains\Flow\History;

use Fapost\Foundation\Flow\History\HistoryEventType;

/**
 * Engine-driven history writer.
 *
 * Lives in Core (not foundation): instrumentation is centralised, handlers
 * never call this directly. The engine, ContactWriter, and EndHandler obtain
 * an instance via {@see HistoryWriterFactory} and dispatch events when the
 * running flow_definition has logging_enabled. Otherwise, factory returns
 * {@see NoOpHistoryWriter} and all calls are absorbed.
 *
 * See ADR State Writer Semantics + brownfield D-6.
 */
interface HistoryWriterInterface
{
    /**
     * @param  array<string, mixed>  $metadata  arbitrary structured payload (subflow info, error details)
     */
    public function record(
        HistoryEventType $eventType,
        string $tenantId,
        string $sessionId,
        string $nodeId,
        ?string $path = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        array $metadata = [],
    ): void;
}
