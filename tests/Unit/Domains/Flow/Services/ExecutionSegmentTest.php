<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Services;

use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\ExecutionSegment;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ExecutionSegmentTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_segment_never_runs_out_before_its_first_step(): void
    {
        $segment = new ExecutionSegment();
        $segment->enter();

        $this->assertFalse($segment->exhausted(maxSteps: 1, maxSeconds: 0));

        $segment->countStep();

        $this->assertTrue($segment->exhausted(maxSteps: 1, maxSeconds: 20));
    }

    public function test_the_time_budget_runs_out_before_the_step_budget(): void
    {
        CarbonImmutable::setTestNow('2026-11-18 10:00:00');
        $segment = new ExecutionSegment();
        $segment->enter();
        $segment->countStep();

        CarbonImmutable::setTestNow('2026-11-18 10:00:19');
        $this->assertFalse($segment->exhausted(maxSteps: 50, maxSeconds: 20));

        CarbonImmutable::setTestNow('2026-11-18 10:00:20');
        $this->assertTrue($segment->exhausted(maxSteps: 50, maxSeconds: 20));
    }

    public function test_nested_calls_share_the_budget_and_only_the_outermost_exit_hands_over_the_stopped_session(): void
    {
        $segment = new ExecutionSegment();
        $stopped = new FlowSession();

        $segment->enter();
        $segment->countStep();
        $segment->enter();
        $segment->countStep();

        $this->assertTrue($segment->exhausted(maxSteps: 2, maxSeconds: 20));

        $segment->stopAt($stopped);

        $this->assertNull($segment->leave());
        $this->assertSame($stopped, $segment->leave());
    }

    public function test_the_next_outermost_call_starts_a_fresh_segment(): void
    {
        $segment = new ExecutionSegment();
        $segment->enter();
        $segment->countStep();
        $segment->stopAt(new FlowSession());
        $segment->leave();

        $segment->enter();

        $this->assertFalse($segment->exhausted(maxSteps: 1, maxSeconds: 20));
        $this->assertNull($segment->leave());
    }
}
