<?php

declare(strict_types=1);

namespace App\Domains\Flow\Logging;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use JsonException;

final readonly class FlowLogWriter
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function write(FlowLogEntry $entry): void
    {
        $this->connection->table('flow_logs')->insert([
            'id'            => Str::ulid()->toRfc4122(),
            'session_id'    => $entry->sessionId,
            'node_id'       => $entry->nodeId,
            'node_type'     => $entry->nodeType,
            'node_version'  => $entry->nodeVersion,
            'status'        => $entry->status->value,
            'source_handle' => $entry->sourceHandle,
            'state_changes' => $this->encodeNullable($entry->stateChanges),
            'resolved'      => $this->encodeNullable($entry->resolved),
            'error'         => $this->encodeNullable($entry->error),
            'created_at'    => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $value
     *
     * @throws JsonException
     */
    private function encodeNullable(?array $value): ?string
    {
        if (null === $value) {
            return null;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
