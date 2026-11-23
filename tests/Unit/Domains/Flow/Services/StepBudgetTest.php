<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Services\StepBudget;
use Carbon\CarbonImmutable;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use PHPUnit\Framework\TestCase;

final class StepBudgetTest extends TestCase
{
    private const array LOOP = ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => ['iterator_name' => 'i']];

    public function test_a_conversation_counts_a_loop_s_iterations_from_where_the_call_found_it(): void
    {
        // The contact answered in the loop's body 10 times before: this call starts the 11th iteration.
        $budget = StepBudget::forConversation(100, 5, CarbonImmutable::now());

        for ($iterator = 11; $iterator <= 15; ++$iterator) {
            $this->assertNull($budget->record(self::LOOP, $this->intoBody(), ['flow' => ['i' => $iterator]]));
        }

        $limit = $budget->record(self::LOOP, $this->intoBody(), ['flow' => ['i' => 16]]);

        $this->assertNotNull($limit);
        $this->assertStringContainsString("Loop node 'loop' exceeded 5 iterations", $limit->getMessage());
    }

    public function test_a_conversation_loop_entered_again_after_its_exit_counts_from_its_first_iteration(): void
    {
        // The call resumed the loop at its 4th iteration, the loop finished, and the flow enters it again.
        $budget = StepBudget::forConversation(100, 3, CarbonImmutable::now());

        $this->assertNull($budget->record(self::LOOP, $this->intoBody(), ['flow' => ['i' => 4]]));
        $this->assertNull($budget->record(self::LOOP, new NodeExecutionResult(status: NodeExecutionStatus::Executed, sourceHandle: 'default'), []));

        for ($iterator = 1; $iterator <= 3; ++$iterator) {
            $this->assertNull($budget->record(self::LOOP, $this->intoBody(), ['flow' => ['i' => $iterator]]));
        }

        $this->assertNotNull($budget->record(self::LOOP, $this->intoBody(), ['flow' => ['i' => 4]]));
    }

    public function test_an_automation_run_counts_a_loop_s_iterations_from_its_first_one(): void
    {
        $budget = StepBudget::forAutomationRun(maxSharedSteps: 100, maxLoopIterations: 5, sharedSteps: 0, loopSteps: 0, iterationSteps: 0, nodes: [], state: []);

        $this->assertNull($budget->record(self::LOOP, $this->intoBody(), ['flow' => ['i' => 5]]));
        $this->assertNotNull($budget->record(self::LOOP, $this->intoBody(), ['flow' => ['i' => 6]]));
    }

    public function test_the_iterator_a_first_pass_writes_counts_as_the_first_iteration(): void
    {
        $budget = StepBudget::forAutomationRun(maxSharedSteps: 100, maxLoopIterations: 1, sharedSteps: 0, loopSteps: 0, iterationSteps: 0, nodes: [], state: []);
        $first  = new NodeExecutionResult(status: NodeExecutionStatus::Executed, sourceHandle: 'loop', stateChanges: ['flow.i' => 1]);

        $this->assertNull($budget->record(self::LOOP, $first, []));
    }

    public function test_the_ceiling_follows_small_settings_and_never_passes_ten_thousand(): void
    {
        $this->assertSame(20, StepBudget::forConversation(10, 1, CarbonImmutable::now())->ceiling());
        $this->assertSame(10000, StepBudget::forConversation(100, 1000, CarbonImmutable::now())->ceiling());
    }

    public function test_an_automation_run_starts_from_its_persisted_counts(): void
    {
        $budget = StepBudget::forAutomationRun(maxSharedSteps: 10, maxLoopIterations: 5, sharedSteps: 9, loopSteps: 0, iterationSteps: 0, nodes: [], state: []);

        $this->assertNull($budget->charge('n1'));
        $this->assertFalse($budget->lastStepInLoop());
        $this->assertStringContainsString('exceeded 10 steps outside loops', (string) $budget->charge('n2')?->getMessage());
    }

    public function test_a_segment_that_starts_in_a_loop_body_counts_its_steps_as_loop_steps(): void
    {
        $nodes  = [self::LOOP, ['id' => 'body', 'type' => 'assign', 'version' => 1, 'config' => []]];
        $budget = StepBudget::forAutomationRun(maxSharedSteps: 10, maxLoopIterations: 5, sharedSteps: 10, loopSteps: 0, iterationSteps: 0, nodes: $nodes, state: ['flow' => ['i' => 3]]);

        // The shared budget is spent, but the body is not charged to it.
        $this->assertNull($budget->charge('body'));
        $this->assertTrue($budget->lastStepInLoop());
    }

    public function test_a_segment_that_starts_in_a_loop_body_goes_on_counting_the_iteration_in_progress(): void
    {
        // The iteration had taken 9 steps in earlier segments without reaching its loop_end.
        $nodes  = [self::LOOP, ['id' => 'body', 'type' => 'assign', 'version' => 1, 'config' => []]];
        $budget = StepBudget::forAutomationRun(maxSharedSteps: 10, maxLoopIterations: 5, sharedSteps: 1, loopSteps: 9, iterationSteps: 9, nodes: $nodes, state: ['flow' => ['i' => 1]]);

        $this->assertNull($budget->charge('body'));
        $this->assertSame(10, $budget->iterationSteps());
        $this->assertStringContainsString(
            "One iteration of loop node 'loop' exceeded 10 steps",
            (string) $budget->charge('body')?->getMessage(),
        );
    }

    public function test_the_persisted_iteration_steps_count_only_inside_a_loop_body(): void
    {
        $budget = StepBudget::forAutomationRun(maxSharedSteps: 10, maxLoopIterations: 5, sharedSteps: 1, loopSteps: 9, iterationSteps: 9, nodes: [self::LOOP], state: []);

        $this->assertSame(0, $budget->iterationSteps());
    }

    public function test_a_loop_end_and_the_loop_s_exit_start_the_iteration_count_afresh(): void
    {
        $budget = StepBudget::forAutomationRun(maxSharedSteps: 10, maxLoopIterations: 5, sharedSteps: 1, loopSteps: 4, iterationSteps: 4, nodes: [self::LOOP], state: ['flow' => ['i' => 1]]);

        $this->assertNull($budget->charge('le'));
        $this->assertNull($budget->record(['id' => 'le', 'type' => 'loop_end', 'version' => 1, 'config' => []], new NodeExecutionResult(status: NodeExecutionStatus::Executed), []));
        $this->assertSame(0, $budget->iterationSteps());

        $this->assertNull($budget->charge('loop'));
        $this->assertNull($budget->record(self::LOOP, new NodeExecutionResult(status: NodeExecutionStatus::Executed, sourceHandle: 'default'), []));
        $this->assertSame(0, $budget->iterationSteps());
    }

    public function test_passing_into_another_loop_without_an_exit_keeps_counting_the_iteration(): void
    {
        $other  = ['id' => 'other', 'type' => 'loop', 'version' => 1, 'config' => ['iterator_name' => 'j']];
        $budget = StepBudget::forAutomationRun(maxSharedSteps: 10, maxLoopIterations: 5, sharedSteps: 1, loopSteps: 6, iterationSteps: 6, nodes: [self::LOOP, $other], state: ['flow' => ['i' => 1]]);

        $this->assertNull($budget->charge('other'));
        $this->assertNull($budget->record($other, $this->intoBody(), ['flow' => ['i' => 1, 'j' => 1]]));
        $this->assertSame(7, $budget->iterationSteps());
    }

    public function test_the_ceiling_counts_both_persisted_counts(): void
    {
        $budget = StepBudget::forAutomationRun(maxSharedSteps: 10, maxLoopIterations: 1, sharedSteps: 2, loopSteps: 18, iterationSteps: 0, nodes: [], state: []);

        $this->assertStringContainsString('ceiling of 20 steps', (string) $budget->charge('n1')?->getMessage());
    }

    public function test_a_conversation_loop_stops_once_the_call_ran_too_long(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 10:00:00');

        try {
            $budget = StepBudget::forConversation(100, 1000, CarbonImmutable::now());
            $this->assertNull($budget->record(self::LOOP, $this->intoBody(), ['flow' => ['i' => 1]]));
            $this->assertNull($budget->charge('body'));

            CarbonImmutable::setTestNow('2026-11-19 10:00:30');

            $this->assertStringContainsString(
                "Loop node 'loop' was still running after 30 seconds",
                (string) $budget->charge('body')?->getMessage(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_steps_outside_a_loop_and_automation_loops_have_no_time_limit(): void
    {
        CarbonImmutable::setTestNow('2026-11-19 10:00:00');

        try {
            $conversation = StepBudget::forConversation(100, 1000, CarbonImmutable::now());
            $automation   = StepBudget::forAutomationRun(maxSharedSteps: 100, maxLoopIterations: 1000, sharedSteps: 0, loopSteps: 0, iterationSteps: 0, nodes: [self::LOOP], state: ['flow' => ['i' => 2]]);

            CarbonImmutable::setTestNow('2026-11-19 10:05:00');

            $this->assertNull($conversation->charge('n1'));
            $this->assertNull($automation->charge('body'));
            $this->assertTrue($automation->lastStepInLoop());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_the_active_loop_follows_the_loop_node_and_its_exit(): void
    {
        $budget = StepBudget::forConversation(100, 5, CarbonImmutable::now());
        $this->assertNull($budget->activeLoopNodeId());

        $this->assertNull($budget->record(self::LOOP, $this->intoBody(), ['flow' => ['i' => 1]]));
        $this->assertSame('loop', $budget->activeLoopNodeId());

        $this->assertNull($budget->record(self::LOOP, new NodeExecutionResult(status: NodeExecutionStatus::Executed, sourceHandle: 'default'), []));
        $this->assertNull($budget->activeLoopNodeId());

        $segment = StepBudget::forAutomationRun(maxSharedSteps: 10, maxLoopIterations: 5, sharedSteps: 1, loopSteps: 0, iterationSteps: 0, nodes: [self::LOOP], state: ['flow' => ['i' => 2]]);
        $this->assertSame('loop', $segment->activeLoopNodeId());
    }

    public function test_the_loop_iterations_limit_leaves_room_for_three_steps_an_iteration_and_a_reserve(): void
    {
        $this->assertLessThanOrEqual(
            FlowEngineInterface::MAX_STEPS_PER_RUN - 1000,
            FlowEngineInterface::MAX_LOOP_ITERATIONS_LIMIT * 3,
        );
    }

    private function intoBody(): NodeExecutionResult
    {
        return new NodeExecutionResult(status: NodeExecutionStatus::Executed, sourceHandle: 'loop');
    }
}
