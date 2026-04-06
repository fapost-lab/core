<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTO;

use App\Domains\Flow\Contracts\FlowSessionInterface;

final readonly class NodeExecutionContext
{
    /**
     * @param  array<string, mixed>  $nodeConfig
     */
    public function __construct(
        public FlowSessionInterface $session,
        public array $nodeConfig,
        public int $nodeVersion,
        public string $nodeId,
    ) {
    }
}
