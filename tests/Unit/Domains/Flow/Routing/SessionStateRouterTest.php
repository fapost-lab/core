<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Routing;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Routing\SessionRoutingDecision;
use App\Domains\Flow\Routing\SessionStateRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SessionStateRouterTest extends TestCase
{
    /**
     * @return array<string, array{0: FlowSessionStatus, 1: SessionRoutingDecision}>
     */
    public static function statusDecisions(): array
    {
        return [
            'waiting input'      => [FlowSessionStatus::WaitingInput, SessionRoutingDecision::ResumeWaiting],
            'paused subflow'     => [FlowSessionStatus::PausedSubflow, SessionRoutingDecision::DropBusy],
            'active'             => [FlowSessionStatus::Active, SessionRoutingDecision::DropSilent],
            'paused'             => [FlowSessionStatus::Paused, SessionRoutingDecision::DropBusy],
            'pending'            => [FlowSessionStatus::Pending, SessionRoutingDecision::DropBusy],
            'completed'          => [FlowSessionStatus::Completed, SessionRoutingDecision::StartViaTrigger],
            'ended'              => [FlowSessionStatus::Ended, SessionRoutingDecision::StartViaTrigger],
            'failed'             => [FlowSessionStatus::Failed, SessionRoutingDecision::StartViaTrigger],
            'cancelled'          => [FlowSessionStatus::Cancelled, SessionRoutingDecision::StartViaTrigger],
            'expired'            => [FlowSessionStatus::Expired, SessionRoutingDecision::StartViaTrigger],
            'terminated by user' => [FlowSessionStatus::TerminatedByUser, SessionRoutingDecision::StartViaTrigger],
        ];
    }

    public function test_no_session_starts_via_trigger(): void
    {
        $this->assertSame(SessionRoutingDecision::StartViaTrigger, (new SessionStateRouter())->decide(null));
    }

    /**
     * A `paused_subflow` parent must never be resumed by an inbound message: resuming it would
     * re-run its subflow node and spawn a second child. The repository never selects it, so
     * DropBusy is the defensive answer if one ever leaks through.
     */
    #[DataProvider('statusDecisions')]
    public function test_each_status_maps_to_its_decision(FlowSessionStatus $status, SessionRoutingDecision $expected): void
    {
        $session         = new FlowSession();
        $session->status = $status;

        $this->assertSame($expected, (new SessionStateRouter())->decide($session));
    }

    public function test_every_status_is_covered(): void
    {
        $this->assertCount(count(FlowSessionStatus::cases()), self::statusDecisions());
    }
}
