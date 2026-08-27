<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use Fapost\Foundation\DTO\IncomingMessage;

/**
 * Rich execution context used inside the engine loop (session + definition + optional inbound message).
 *
 * Handlers receive {@see \Fapost\Foundation\DTO\NodeExecutionContext} built from this data.
 */
final readonly class NodeExecutionContext
{
    public function __construct(
        public FlowSession $session,
        public FlowDefinition $definition,
        public ?IncomingMessage $incoming = null,
    ) {
    }
}
