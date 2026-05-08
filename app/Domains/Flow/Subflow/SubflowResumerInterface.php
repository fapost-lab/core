<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use App\Domains\Flow\Models\FlowSession;

/**
 * Resumes a parent flow_session when its subflow child reaches an end node.
 * Invoked by the engine immediately after persisting the child's terminal
 * state. A no-op implementation ships in V1; the real resume logic lands
 * with the subflow node (Phase C-4).
 */
interface SubflowResumerInterface
{
    /**
     * @param  FlowSession  $child      The just-ended child session.
     * @param  string       $endStatus  One of: success, cancelled, failed.
     */
    public function resumeIfChild(FlowSession $child, string $endStatus): void;
}
