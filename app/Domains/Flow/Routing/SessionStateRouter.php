<?php

declare(strict_types=1);

namespace App\Domains\Flow\Routing;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;

/**
 * Pure decision function: given the current active session for a (tenant,
 * contact, assistant) triple, classify the inbound message into one of
 * {@see SessionRoutingDecision} outcomes.
 *
 * Encodes the routing matrix from ADR Message Routing § Step 4 (as amended in 2026-10):
 *
 *   no session                → StartViaTrigger
 *   waiting_input             → ResumeWaiting
 *   paused_subflow            → DropBusy (defensive; see below)
 *   active                    → DropSilent (rare race — lock should have prevented this)
 *   paused                    → DropBusy
 *   ended/expired/failed/…    → StartViaTrigger
 *
 * A `paused_subflow` parent is never returned by the session repository: its
 * live child (active / waiting_input) is the selected session, so the message
 * resumes the child through `ResumeWaiting`. `DropBusy` is the defensive
 * answer should a parent leak through anyway — it must never start a parallel
 * session for the same contact.
 */
final readonly class SessionStateRouter
{
    public function decide(?FlowSession $session): SessionRoutingDecision
    {
        if (null === $session) {
            return SessionRoutingDecision::StartViaTrigger;
        }

        return match ($session->status) {
            FlowSessionStatus::WaitingInput  => SessionRoutingDecision::ResumeWaiting,
            FlowSessionStatus::PausedSubflow => SessionRoutingDecision::DropBusy,
            FlowSessionStatus::Active        => SessionRoutingDecision::DropSilent,
            FlowSessionStatus::Paused        => SessionRoutingDecision::DropBusy,
            FlowSessionStatus::Pending       => SessionRoutingDecision::DropBusy,
            FlowSessionStatus::Completed,
            FlowSessionStatus::Ended,
            FlowSessionStatus::Failed,
            FlowSessionStatus::Cancelled,
            FlowSessionStatus::Expired,
            FlowSessionStatus::TerminatedByUser => SessionRoutingDecision::StartViaTrigger,
        };
    }
}
