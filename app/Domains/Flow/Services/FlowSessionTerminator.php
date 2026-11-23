<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Live\FlowActivityNotifier;
use App\Domains\Flow\Models\FlowSession;

/**
 * Ends a contact's conversation from outside it: a global command (`/reset`, a flow-starting command), or a
 * `start_flow` start replacing a conversation that stopped moving.
 *
 * The session is written `terminated_by_user` past the optimistic lock (a stuck worker must not keep it alive), its
 * pending operator request is cancelled — the thread goes back to the bot instead of waiting for an operator forever —
 * and the parents a subflow chain left waiting in `paused_subflow` are ended with it. A thread an operator already
 * handles is left to them.
 */
final readonly class FlowSessionTerminator
{
    public function __construct(
        private FlowActivityNotifier $activity,
        private OperatorRequestsInterface $operatorRequests,
    ) {
    }

    public function terminate(FlowSession $session): void
    {
        $this->forceTerminate($session);

        $this->operatorRequests->cancelForSession(
            (string) $session->tenant_id,
            (string) $session->assistant_id,
            (string) $session->contact_id,
            (string) $session->getKey(),
        );

        $this->terminateWaitingAncestors($session);
    }

    /**
     * The terminal status, written past the optimistic lock.
     */
    private function forceTerminate(FlowSession $session): void
    {
        $session->newQuery()
            ->whereKey($session->getKey())
            ->update([
                'status'          => FlowSessionStatus::TerminatedByUser->value,
                'current_node_id' => null,
                'updated_at'      => now(),
            ]);

        // A query write raises no model event, so the live screens are told here.
        $this->activity->touched($session);
    }

    /**
     * The whole conversation ends, not only its leaf: the parents a subflow left in `paused_subflow` would otherwise
     * wait for a child that no longer exists, until the timeout sweeper reaches them.
     */
    private function terminateWaitingAncestors(FlowSession $session): void
    {
        $seen     = [(string) $session->getKey()];
        $parentId = $session->parent_session_id;

        while (is_string($parentId) && '' !== $parentId && ! in_array($parentId, $seen, true)) {
            $seen[] = $parentId;

            $parent = FlowSession::query()->find($parentId);

            if (! $parent instanceof FlowSession || FlowSessionStatus::PausedSubflow !== $parent->status) {
                return;
            }

            $this->forceTerminate($parent);

            $parentId = $parent->parent_session_id;
        }
    }
}
