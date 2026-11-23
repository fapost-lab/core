<?php

declare(strict_types=1);

namespace App\Domains\Flow\Trace;

use App\Domains\Flow\Models\FlowSession;

/**
 * What every trace row of one step names: the session, the node, its type and version, and the loops it runs in.
 *
 * Built once a step by the engine and handed to each {@see NodeTraceRecorder} call of that step. The type and version are
 * null only for a step limit, which fails the session before a node is resolved.
 */
final readonly class NodeTraceContext
{
    /**
     * @param  array<string, int>  $iterations  {@see LoopIterations::of()}
     */
    public function __construct(
        public FlowSession $session,
        public string $nodeId,
        public ?string $type,
        public ?int $version,
        public array $iterations,
    ) {
    }
}
