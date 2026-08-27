<?php

declare(strict_types=1);

namespace App\Domains\Flow\Logging;

final readonly class FlowLogEntry
{
    /**
     * @param  array<string, mixed>|null  $stateChanges
     * @param  array<string, mixed>|null  $resolved
     * @param  array<string, mixed>|null  $error
     */
    public function __construct(
        public string $sessionId,
        public string $nodeId,
        public string $nodeType,
        public int $nodeVersion,
        public FlowLogStatus $status,
        public ?string $sourceHandle = null,
        public ?array $stateChanges = null,
        public ?array $resolved = null,
        public ?array $error = null,
    ) {
    }
}
