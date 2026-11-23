<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Events;

/**
 * A broadcast run reached `completed`: every recipient was processed, or the audience was empty.
 *
 * Raised once per broadcast by the job that made the transition (the last recipient job, or the run job for an empty
 * audience); a cancelled or failed run does not raise it. Broadcasting does not depend on Flow: the Flow domain
 * listens and turns it into the `core.broadcast.completed` event trigger. Dispatched synchronously.
 *
 * `chainDepth` is the broadcast's own (`broadcasts.chain_depth`): the depth of the event chain its completion continues.
 * A console broadcast starts none (0); one a flow started carries its run's depth plus one, so a flow that broadcasts
 * when a broadcast completes stops at the chain limit instead of broadcasting for ever.
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
        public int $chainDepth = 0,
    ) {
    }
}
