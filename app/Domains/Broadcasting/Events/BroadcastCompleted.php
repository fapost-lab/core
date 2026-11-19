<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Events;

/**
 * A broadcast run reached `completed`: every recipient was processed, or the audience was empty.
 *
 * Raised once per broadcast by the job that made the transition (the last recipient job, or the run job for an empty
 * audience); a cancelled or failed run does not raise it. Broadcasting does not depend on Flow: the Flow domain
 * listens and turns it into the `core.broadcast.completed` event trigger. Dispatched synchronously.
 */
final readonly class BroadcastCompleted
{
    public function __construct(
        public string $tenantId,
        public string $broadcastId,
        public string $name,
        public int $recipientsTotal,
        public int $sent,
        public int $failed,
    ) {
    }
}
