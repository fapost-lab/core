<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTO;

use App\Domains\Flow\Enums\NodeExecutionStatus;

final readonly class NodeExecutionResult
{
    /**
     * @param  array<string, mixed>  $stateChanges
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public NodeExecutionStatus $status,
        public ?string $transition = null,
        public array $stateChanges = [],
        public array $metadata = [],
    ) {
    }
}
