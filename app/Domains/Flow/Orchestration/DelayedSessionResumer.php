<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Flow\Contracts\DelayResumeSchedulerInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\SystemStateKeys;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Re-enters a session parked on a `delay` node once its `resume_at` is due —
 * the receiving side of {@see DelayResumeSchedulerInterface}.
 *
 * Runs outside the routing pipeline, so it claims the session lock itself and
 * re-reads the session under it: an inbound message may have moved the flow on
 * while the resume was queued, and a loop may have re-scheduled the same node.
 * Anything other than "still parked on this node for this resume_at" is a stale
 * resume and a silent no-op, which makes a repeated or duplicate job harmless.
 */
final readonly class DelayedSessionResumer
{
    public function __construct(
        private FlowSessionRepositoryInterface $sessions,
        private FlowExecutionGuardInterface $guard,
        private FlowEngineInterface $engine,
        private DelayResumeSchedulerInterface $scheduler,
    ) {
    }

    public function resume(string $sessionId, string $nodeId, DateTimeInterface $resumeAt): void
    {
        $session = $this->sessions->findById($sessionId);

        if (null === $session) {
            return;
        }

        $this->guard->run(
            tenantId: (string)$session->tenant_id,
            contactId: (string)$session->contact_id,
            assistantId: (string)$session->assistant_id,
            callback: function () use ($session, $nodeId, $resumeAt): void {
                $session->refresh();

                if (! $this->isParkedAt($session, $nodeId, $resumeAt)) {
                    return;
                }

                // Released early (clock skew between hosts): running now would only
                // re-park the session, with nothing left to wake it up again.
                if (Carbon::now()->lt($resumeAt)) {
                    $this->scheduler->schedule((string)$session->tenant_id, (string)$session->getKey(), $nodeId, $resumeAt);

                    return;
                }

                $this->engine->runSession($session);
            },
        );
    }

    private function isParkedAt(FlowSession $session, string $nodeId, DateTimeInterface $resumeAt): bool
    {
        $stored = data_get($session->state, SystemStateKeys::DELAY_NODE_PREFIX . ".{$nodeId}.resume_at");

        return FlowSessionStatus::WaitingInput === $session->status
            && $nodeId === $session->current_node_id
            && is_string($stored)
            && Carbon::parse($stored)->equalTo($resumeAt);
    }
}
