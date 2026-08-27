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
 * Encodes the routing matrix from ADR Message Routing § Step 4 verbatim:
 *
 *   no session                → StartViaTrigger
 *   waiting_input             → ResumeWaiting
 *   paused_subflow            → RouteToSubflowChild (Subflow ADR routing rule)
 *   active                    → DropSilent (rare race — lock should have prevented this)
 *   paused                    → DropBusy
 *   ended/expired/failed/…    → StartViaTrigger
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
            FlowSessionStatus::PausedSubflow => SessionRoutingDecision::RouteToSubflowChild,
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
