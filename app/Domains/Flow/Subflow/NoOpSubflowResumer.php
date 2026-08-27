<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use App\Domains\Flow\Models\FlowSession;

/**
 * Default subflow resumer used until the subflow node ships (Phase C-4).
 * For top-level sessions (parent_session_id IS NULL) the contract is a no-op
 * by design; this implementation extends that no-op to children too while
 * the resume machinery is in flight.
 */
final readonly class NoOpSubflowResumer implements SubflowResumerInterface
{
    public function resumeIfChild(FlowSession $child, string $endStatus): void
    {
        // Intentionally empty — Phase C-4 wires the real resume.
    }
}
