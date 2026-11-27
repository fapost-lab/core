<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Broadcasting;

use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\RecipientFailureReason as Reason;
use App\Domains\Broadcasting\Enums\RecipientStatus as Status;
use PHPUnit\Framework\TestCase;

/**
 * The category the report shows for a recipient, read from what the send path stored: every text the send path writes
 * lands in a category, and an unknown failure text is a generic one, never echoed.
 */
final class RecipientFailureReasonTest extends TestCase
{
    public function test_a_pending_or_sent_recipient_has_nothing_to_explain(): void
    {
        $this->assertNull(Reason::of(Status::Pending, null, BroadcastStatus::Running, null));
        $this->assertNull(Reason::of(Status::Sent, null, BroadcastStatus::Completed, null));
    }

    public function test_a_failure_is_a_missing_contact_or_a_refused_delivery(): void
    {
        $this->assertSame(Reason::ContactMissing, Reason::of(Status::Failed, Reason::CONTACT_MISSING_ERROR, BroadcastStatus::Completed, null));
        $this->assertSame(Reason::DeliveryFailed, Reason::of(Status::Failed, 'https://api.telegram.org/bot1:SECRET/sendMessage timed out', BroadcastStatus::Completed, null));
        $this->assertSame(Reason::DeliveryFailed, Reason::of(Status::Failed, null, BroadcastStatus::Completed, null));
    }

    public function test_a_skip_names_why_the_run_stopped(): void
    {
        $this->assertSame(Reason::RunFailed, Reason::of(Status::Skipped, BroadcastStatus::STOP_REASON_RUN_FAILED, BroadcastStatus::Failed, BroadcastStatus::STOP_REASON_RUN_FAILED));
        $this->assertSame(Reason::LimitReached, Reason::of(Status::Skipped, BroadcastStatus::STOP_REASON_LIMIT_REACHED, BroadcastStatus::Cancelled, BroadcastStatus::STOP_REASON_LIMIT_REACHED));
        // The first recipient the limit refused carries the limit's own message.
        $this->assertSame(Reason::LimitReached, Reason::of(Status::Skipped, 'Limit "outbound_messages" reached for this period.', BroadcastStatus::Cancelled, BroadcastStatus::STOP_REASON_LIMIT_REACHED));
        $this->assertSame(Reason::Stopped, Reason::of(Status::Skipped, 'something new', BroadcastStatus::Cancelled, null));
    }

    public function test_a_skip_for_an_empty_message_is_no_text_even_in_a_cancelled_run(): void
    {
        $this->assertSame(Reason::NoText, Reason::of(Status::Skipped, Reason::NO_TEXT_ERROR, BroadcastStatus::Cancelled, BroadcastStatus::STOP_REASON_LIMIT_REACHED));
        $this->assertSame(Reason::NoText, Reason::of(Status::Skipped, Reason::NO_TEXT_ERROR, BroadcastStatus::Completed, null));
    }

    public function test_an_older_skip_without_a_text_is_a_cancelled_run_or_a_message_without_text(): void
    {
        $this->assertSame(Reason::Stopped, Reason::of(Status::Skipped, null, BroadcastStatus::Cancelled, null));
        $this->assertSame(Reason::NoText, Reason::of(Status::Skipped, null, BroadcastStatus::Completed, null));
        $this->assertSame(Reason::NoText, Reason::of(Status::Skipped, '', BroadcastStatus::Running, null));
    }
}
