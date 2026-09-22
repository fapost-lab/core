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
 * Re-enters a session parked on a due timed resume — the receiving side of
 * {@see DelayResumeSchedulerInterface}. Two independent forms share the same
 * job and the same staleness handling:
 *
 *  - A `delay` node: `waiting_input` + `system.delay.{nodeId}.resume_at`. The
 *    node reads its own marker on the next visit, so the engine is re-entered
 *    without the wake flag.
 *  - `NodeExecutionResult::delayed(resumeAt: ...)`: `paused` +
 *    `system.delayed.{nodeId}.resume_at` (written by
 *    {@see \App\Domains\Flow\Services\FlowSessionPersister}). The node cannot
 *    tell this apart from a first visit on its own, so the engine passes
 *    {@see \Fapost\Foundation\DTO\NodeExecutionContext::$resumedAfterDelay}.
 *
 * Runs outside the routing pipeline, so it claims the session lock itself and
 * re-reads the session under it: an inbound message may have moved the flow on
 * while the resume was queued, and a loop may have re-scheduled the same node.
 * Anything other than "still parked on this node for this resume_at" (in
 * either form) is a stale resume and a silent no-op, which makes a repeated
 * or duplicate job harmless.
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

                $resumedAfterDelay = $this->resolveResumedAfterDelay($session, $nodeId, $resumeAt);

                if (null === $resumedAfterDelay) {
                    return;
                }

                // Released early (clock skew between hosts): running now would only
                // re-park the session, with nothing left to wake it up again.
                if (Carbon::now()->lt($resumeAt)) {
                    $this->scheduler->schedule((string)$session->tenant_id, (string)$session->getKey(), $nodeId, $resumeAt);

                    return;
                }

                $this->engine->runSession($session, resumedAfterDelay: $resumedAfterDelay);
            },
        );
    }

    /**
     * Wakes {@code $session} inline when it is `paused` on a due
     * `delayed(resumeAt: ...)` marker for its current node — the form the
     * routing pipeline hits when an inbound message arrives after `resume_at`
     * but before (or instead of) the scheduled job. Runs under the caller's
     * lock: {@see FlowExecutionGuardInterface} is re-entrant for a scope the
     * caller already holds (see {@see \App\Infrastructure\Flow\FlowExecutionGuard}),
     * which is exactly the routing pipeline's situation — it acquires the
     * session lock before it can classify session state.
     *
     * No-op (returns {@code $session} unchanged) when the session isn't
     * parked in this form, or its `resume_at` hasn't arrived yet — including
     * a legacy `paused` row with no marker at all, which must never crash.
     */
    public function wakeIfDue(FlowSession $session): FlowSession
    {
        $nodeId = $session->current_node_id;

        if (! is_string($nodeId) || '' === $nodeId) {
            return $session;
        }

        $stored = data_get($session->state, SystemStateKeys::DELAYED_RESULT_PREFIX . ".{$nodeId}.resume_at");

        if (! is_string($stored)) {
            return $session;
        }

        $resumeAt = Carbon::parse($stored);

        if (FlowSessionStatus::Paused !== $session->status || Carbon::now()->lt($resumeAt)) {
            return $session;
        }

        return $this->guard->run(
            tenantId: (string)$session->tenant_id,
            contactId: (string)$session->contact_id,
            assistantId: (string)$session->assistant_id,
            callback: function () use ($session, $nodeId, $resumeAt): FlowSession {
                $session->refresh();

                if (! $this->isPausedAwaitingResume($session, $nodeId, $resumeAt)) {
                    return $session;
                }

                return $this->engine->runSession($session, resumedAfterDelay: true);
            },
        );
    }

    /**
     * Matches {@code $session} against both parked forms for {@code $nodeId}
     * and {@code $resumeAt}, and reports which {@code resumedAfterDelay}
     * value the engine must see for the one that matches. Null means neither
     * form matches — a stale or duplicate resume, to be treated as a no-op.
     */
    private function resolveResumedAfterDelay(FlowSession $session, string $nodeId, DateTimeInterface $resumeAt): ?bool
    {
        if ($this->isParkedAtDelayNode($session, $nodeId, $resumeAt)) {
            return false;
        }

        if ($this->isPausedAwaitingResume($session, $nodeId, $resumeAt)) {
            return true;
        }

        return null;
    }

    private function isParkedAtDelayNode(FlowSession $session, string $nodeId, DateTimeInterface $resumeAt): bool
    {
        $stored = data_get($session->state, SystemStateKeys::DELAY_NODE_PREFIX . ".{$nodeId}.resume_at");

        return FlowSessionStatus::WaitingInput === $session->status
            && $nodeId === $session->current_node_id
            && is_string($stored)
            && Carbon::parse($stored)->equalTo($resumeAt);
    }

    private function isPausedAwaitingResume(FlowSession $session, string $nodeId, DateTimeInterface $resumeAt): bool
    {
        $stored = data_get($session->state, SystemStateKeys::DELAYED_RESULT_PREFIX . ".{$nodeId}.resume_at");

        return FlowSessionStatus::Paused === $session->status
            && $nodeId === $session->current_node_id
            && is_string($stored)
            && Carbon::parse($stored)->equalTo($resumeAt);
    }
}
