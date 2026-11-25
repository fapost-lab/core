<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\ContactFlowStartOutcome;
use App\Domains\Flow\Services\ContactFlowStartStatus;
use LogicException;
use Tests\TestCase;

/**
 * The started session of a start outcome: there for a start that happened, an error for any other.
 */
final class ContactFlowStartOutcomeTest extends TestCase
{
    public function test_a_started_outcome_gives_its_session(): void
    {
        $session = new FlowSession();

        $this->assertSame($session, ContactFlowStartOutcome::started($session, 0)->startedSession());
    }

    public function test_an_outcome_that_started_nothing_has_no_started_session(): void
    {
        $this->expectException(LogicException::class);

        ContactFlowStartOutcome::of(ContactFlowStartStatus::Duplicate)->startedSession();
    }
}
