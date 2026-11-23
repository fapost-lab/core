<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\SessionLockRegistry;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowExecutionLimitExceededException;
use App\Domains\Flow\Exceptions\SessionLockLostException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\AutomationRunner;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Subflow\SubflowTimeoutSweeper;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Jobs\Flow\ContinueAutomationRunJob;
use App\Jobs\Flow\ResumeDelayedFlowSessionJob;
use App\Jobs\Flow\StartAutomationRunJob;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Tests\Feature\FeatureTestCase;

/**
 * Idempotent, segmented execution of contactless automation runs. No trigger fires yet: these tests are the entry
 * point, dispatching {@see StartAutomationRunJob} by hand and running every {@see ContinueAutomationRunJob} the
 * engine queues (the queue is faked, so a continuation never runs inline inside the segment that queued it).
 */
#[Group('redis')]
final class AutomationRunTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private string $triggerId;

    /** @var int how many ContinueAutomationRunJob pushes were already run */
    private int $handled = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->app->make(NodeHandlerRegistry::class)->register(AutomationProbeTestHandler::class);
        $this->triggerId = (string) Str::uuid();

        config([
            'flow.automation.segment_steps'   => 2,
            'flow.automation.segment_seconds' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        AutomationProbeTestHandler::reset();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Segment sizes that stop a run in the child, in the parent its child resumed, and on either side of the subflow
     * node, with a child of one or two steps.
     *
     * @return array<string, array{int, int}>  segment steps, child probe nodes
     */
    public static function subflowInLoopSegments(): array
    {
        return [
            'one step a segment'                    => [1, 1],
            'stops in the resumed parent'           => [2, 1],
            'stops in the child'                    => [2, 2],
            'stops around the subflow node'         => [3, 1],
            'stops around the subflow node, longer' => [3, 2],
            'five steps a segment'                  => [5, 1],
            'one segment'                           => [50, 1],
        ];
    }

    public function test_a_run_crosses_several_segments_and_ends_as_one_session(): void
    {
        $flowId = $this->probeChain(5);

        $this->start($flowId, 'slot-1');
        $continuations = $this->drain();

        $session = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(FlowRunKind::Automation, $session->run_kind);
        $this->assertSame('slot-1', $session->fire_key);
        $this->assertSame(6, $session->step_count);
        $this->assertSame(['c1' => 1, 'c2' => 1, 'c3' => 1, 'c4' => 1, 'c5' => 1], AutomationProbeTestHandler::$runs);
        $this->assertCount(2, $continuations, 'Six steps at two a segment: the start and two continuations.');

        foreach ($continuations as $job) {
            $this->assertSame(AutomationRunner::QUEUE, $job->queue);
            $this->assertSame((string) $session->getKey(), $job->sessionId);
        }

        $this->assertNotSame($continuations[0]->segmentId, $continuations[1]->segmentId);
    }

    public function test_a_retried_start_continues_its_run_instead_of_starting_a_second(): void
    {
        $flowId = $this->probeChain(5);

        $this->start($flowId, 'slot-1');
        $this->start($flowId, 'slot-1');
        $this->drain();

        $this->assertSame(1, FlowSession::query()->count());
        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->sole()->status);
        $this->assertSame(['c1' => 1, 'c2' => 1, 'c3' => 1, 'c4' => 1, 'c5' => 1], AutomationProbeTestHandler::$runs);
    }

    public function test_a_start_retried_after_the_run_ended_does_not_run_the_flow_again(): void
    {
        $flowId = $this->probeChain(1);

        $this->start($flowId, 'slot-1');
        $this->drain();
        $this->start($flowId, 'slot-1');

        $this->assertSame(1, FlowSession::query()->count());
        $this->assertSame(['c1' => 1], AutomationProbeTestHandler::$runs);
        Queue::assertNotPushed(ContinueAutomationRunJob::class);
    }

    public function test_another_fire_of_the_same_trigger_starts_its_own_run(): void
    {
        $flowId = $this->probeChain(1);

        $this->start($flowId, 'slot-1');
        $this->start($flowId, 'slot-2');

        $this->assertSame(['slot-1', 'slot-2'], FlowSession::query()->orderBy('fire_key')->pluck('fire_key')->all());
    }

    public function test_a_start_killed_mid_segment_is_retried_into_the_same_run(): void
    {
        config(['flow.automation.segment_steps' => 50]);
        $flowId = $this->flow([
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'c2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => ['action' => 'lose_lock_once']],
            ['id' => 'c3', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);

        try {
            $this->start($flowId, 'slot-1');
            $this->fail('The lost lock must abort the job, as a killed worker would.');
        } catch (SessionLockLostException) {
        }

        $session = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Active, $session->status);
        $this->assertSame('c3', $session->current_node_id);

        $this->start($flowId, 'slot-1');

        $this->assertSame(1, FlowSession::query()->count());
        $this->assertSame(FlowSessionStatus::Ended, $session->refresh()->status);
        $this->assertSame(['c1' => 1, 'c2' => 1, 'c3' => 1], AutomationProbeTestHandler::$runs);
    }

    public function test_a_continuation_killed_mid_segment_is_retried_into_the_same_run(): void
    {
        config(['flow.automation.segment_steps' => 3]);
        $flowId = $this->flow([
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'c2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'c3', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'c4', 'type' => 'automation_probe_test', 'version' => 1, 'config' => ['action' => 'lose_lock_once']],
            ['id' => 'c5', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);

        $this->start($flowId, 'slot-1');
        $continuation = $this->pushedContinuations()[0];

        try {
            $this->app->call([$continuation, 'handle']);
            $this->fail('The lost lock must abort the continuation.');
        } catch (SessionLockLostException) {
        }

        $this->app->call([$continuation, 'handle']);

        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->sole()->status);
        $this->assertSame(['c1' => 1, 'c2' => 1, 'c3' => 1, 'c4' => 1, 'c5' => 1], AutomationProbeTestHandler::$runs);
    }

    public function test_a_stale_or_duplicate_continuation_does_nothing(): void
    {
        $flowId = $this->probeChain(5);

        $this->start($flowId, 'slot-1');
        $first = $this->pushedContinuations()[0];
        $this->app->call([$first, 'handle']);
        $session = FlowSession::query()->sole();
        $version = $session->version;
        $runs    = AutomationProbeTestHandler::$runs;

        // The first continuation already handed the run on: running it again is a duplicate.
        $this->app->call([$first, 'handle']);

        $this->assertSame($version, $session->refresh()->version);
        $this->assertSame($runs, AutomationProbeTestHandler::$runs);
        $this->assertCount(2, $this->pushedContinuations());
    }

    public function test_a_retried_start_leaves_the_continuation_it_queued_stale(): void
    {
        $flowId = $this->probeChain(5);

        $this->start($flowId, 'slot-1');
        $this->start($flowId, 'slot-1');
        [$stale, $valid] = $this->pushedContinuations();

        // The retry ran the second segment (c3, c4) itself and queued the valid continuation.
        $this->app->call([$stale, 'handle']);
        $this->assertSame(['c1' => 1, 'c2' => 1, 'c3' => 1, 'c4' => 1], AutomationProbeTestHandler::$runs);

        $this->app->call([$valid, 'handle']);
        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->sole()->status);
    }

    public function test_the_step_limit_counts_every_segment_of_a_run(): void
    {
        config(['flow.execution.max_iterations' => 10, 'flow.automation.segment_steps' => 3]);
        $flowId = $this->flow([
            ['id' => 'c0', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'c2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
        ], [
            ['id' => 'e0', 'from' => 'c0', 'to' => 'c1', 'handle' => 'default'],
            ['id' => 'e1', 'from' => 'c1', 'to' => 'c2', 'handle' => 'default'],
            ['id' => 'e2', 'from' => 'c2', 'to' => 'c1', 'handle' => 'default'],
        ]);

        $this->start($flowId, 'slot-1');

        try {
            $this->drain();
            $this->fail('A loop with no exit must hit the step limit across segments.');
        } catch (FlowExecutionLimitExceededException) {
        }

        $session = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Failed, $session->status);
        $this->assertSame(10, $session->step_count);
        $this->assertSame(0, $session->loop_step_count);
    }

    public function test_a_runaway_inside_a_loop_body_stops_at_one_iteration_s_steps_over_every_segment(): void
    {
        // The body leads straight back to the loop node, so the iterator never moves and every step after the entry
        // is a step of the same iteration. Segments of 3 steps would restart an in-memory count; the persisted one
        // stops the run at max_iterations steps of the iteration, long before the ceiling of 10 x (5 + 1) = 60.
        config([
            'flow.execution.max_iterations'      => 10,
            'flow.execution.max_loop_iterations' => 5,
            'flow.automation.segment_steps'      => 3,
        ]);
        $flowId = $this->flow([
            ['id' => 'c0', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => [
                'mode'          => 'counted',
                'iterator_name' => 'i',
                'count_source'  => ['type' => 'literal', 'value' => 1],
            ]],
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
        ], [
            ['id' => 'e0', 'from' => 'c0', 'to' => 'loop', 'handle' => 'default'],
            ['id' => 'e1', 'from' => 'loop', 'to' => 'c1', 'handle' => 'loop'],
            ['id' => 'e2', 'from' => 'c1', 'to' => 'loop', 'handle' => 'default'],
        ]);

        $this->start($flowId, 'slot-1');

        try {
            $this->drain();
            $this->fail('A loop body that never reaches its loop_end must hit the iteration\'s step limit across segments.');
        } catch (FlowExecutionLimitExceededException $exception) {
            $this->assertStringContainsString("One iteration of loop node 'loop' exceeded 10 steps", $exception->getMessage());
        }

        $session = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Failed, $session->status);
        $this->assertSame(2, $session->step_count);
        $this->assertSame(10, $session->loop_step_count);
        $this->assertSame(10, $session->loop_iteration_step_count);
        $this->assertSame(['c0' => 1, 'c1' => 5], AutomationProbeTestHandler::$runs);
    }

    public function test_a_runaway_that_left_its_loop_body_by_another_edge_stops_at_one_iteration_s_steps(): void
    {
        // The body leaves the loop by a plain edge instead of its loop_end, so the iterator stays set and the cycle
        // after it still counts as the iteration in progress — in every segment, not only the first.
        config([
            'flow.execution.max_iterations'      => 10,
            'flow.execution.max_loop_iterations' => 5,
            'flow.automation.segment_steps'      => 3,
        ]);
        $flowId = $this->flow([
            ['id' => 'c0', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => [
                'mode'          => 'counted',
                'iterator_name' => 'i',
                'count_source'  => ['type' => 'literal', 'value' => 3],
            ]],
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'c2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'c3', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'e0', 'from' => 'c0', 'to' => 'loop', 'handle' => 'default'],
            ['id' => 'e1', 'from' => 'loop', 'to' => 'c1', 'handle' => 'loop'],
            ['id' => 'e2', 'from' => 'c1', 'to' => 'c2', 'handle' => 'default'],
            ['id' => 'e3', 'from' => 'c2', 'to' => 'c3', 'handle' => 'default'],
            ['id' => 'e4', 'from' => 'c3', 'to' => 'c2', 'handle' => 'default'],
            ['id' => 'e5', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
        ]);

        $this->start($flowId, 'slot-1');

        try {
            $this->drain();
            $this->fail('A cycle after a loop body left by another edge must hit the iteration\'s step limit across segments.');
        } catch (FlowExecutionLimitExceededException $exception) {
            $this->assertStringContainsString("One iteration of loop node 'loop' exceeded 10 steps", $exception->getMessage());
        }

        $session = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Failed, $session->status);
        $this->assertSame(2, $session->step_count);
        $this->assertSame(10, $session->loop_step_count);
        $this->assertSame(['c0' => 1, 'c1' => 1, 'c2' => 5, 'c3' => 4], AutomationProbeTestHandler::$runs);
    }

    public function test_a_loop_longer_than_the_step_budget_runs_to_its_end_across_segments(): void
    {
        // 40 iterations of loop + probe + loop_end are 120 steps, past max_iterations, in segments of 50.
        config(['flow.execution.max_iterations' => 100, 'flow.automation.segment_steps' => 50]);
        $flowId = $this->flow([
            ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => [
                'mode'          => 'counted',
                'iterator_name' => 'i',
                'count_source'  => ['type' => 'literal', 'value' => 40],
            ]],
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'le', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 'loop', 'iterator_name' => 'i']],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'e1', 'from' => 'loop', 'to' => 'c1', 'handle' => 'loop'],
            ['id' => 'e2', 'from' => 'c1', 'to' => 'le', 'handle' => 'default'],
            ['id' => 'e3', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
        ]);

        $this->start($flowId, 'slot-1');
        $this->drain();

        $session = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(['c1' => 40], AutomationProbeTestHandler::$runs);
        // Only entering the loop and the end node ran outside its body; every segment that starts in the body knows it.
        $this->assertSame(2, $session->step_count);
        $this->assertSame(120, $session->loop_step_count);
        // The loop's exit ended the last iteration's count.
        $this->assertSame(0, $session->loop_iteration_step_count);
    }

    public function test_a_loop_s_iteration_limit_counts_over_every_segment_of_a_run(): void
    {
        // Each segment of 3 steps runs about one iteration: only the persisted iterator sees the sixth.
        config(['flow.execution.max_loop_iterations' => 5, 'flow.automation.segment_steps' => 3]);
        $flowId = $this->flow([
            ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => [
                'mode'          => 'counted',
                'iterator_name' => 'i',
                'count_source'  => ['type' => 'literal', 'value' => 6],
            ]],
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'le', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 'loop', 'iterator_name' => 'i']],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'e1', 'from' => 'loop', 'to' => 'c1', 'handle' => 'loop'],
            ['id' => 'e2', 'from' => 'c1', 'to' => 'le', 'handle' => 'default'],
            ['id' => 'e3', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
        ]);

        $this->start($flowId, 'slot-1');

        try {
            $this->drain();
            $this->fail('The sixth iteration must fail the run.');
        } catch (FlowExecutionLimitExceededException $exception) {
            $this->assertStringContainsString("Loop node 'loop' exceeded 5 iterations", $exception->getMessage());
        }

        $this->assertGreaterThan(4, count($this->pushedContinuations()));
        $this->assertSame(['c1' => 5], AutomationProbeTestHandler::$runs);
        $this->assertSame(FlowSessionStatus::Failed, FlowSession::query()->sole()->status);
    }

    public function test_the_time_budget_stops_a_segment_before_its_step_budget(): void
    {
        config(['flow.automation.segment_steps' => 50, 'flow.automation.segment_seconds' => 5]);
        $flowId = $this->flow([
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => ['action' => 'take_6_seconds']],
            ['id' => 'c2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);

        $this->start($flowId, 'slot-1');

        $session = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Active, $session->status);
        $this->assertSame('c2', $session->current_node_id);
        $this->assertCount(1, $this->pushedContinuations());

        $this->drain();
        $this->assertSame(FlowSessionStatus::Ended, $session->refresh()->status);
    }

    public function test_an_installation_default_past_the_maximum_still_stops_a_segment_at_thirty_seconds(): void
    {
        config(['flow.automation.segment_steps' => 50, 'flow.automation.segment_seconds' => 90]);
        $flowId = $this->flow([
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => ['action' => 'take_31_seconds']],
            ['id' => 'c2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);

        $this->start($flowId, 'slot-1');

        $this->assertSame('c2', FlowSession::query()->sole()->current_node_id);
        $this->assertCount(1, $this->pushedContinuations());
    }

    public function test_a_subflow_child_that_runs_out_continues_and_then_hands_back_to_its_parent(): void
    {
        $childFlowId = $this->flow([
            ['id' => 'k1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'k2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'k3', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'k-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);
        $parentFlowId = $this->flow([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
            ['id' => 'p1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'pe1', 'from' => 'p-sub', 'to' => 'p1', 'handle' => 'success'],
            ['id' => 'pe2', 'from' => 'p1', 'to' => 'p-end', 'handle' => 'default'],
        ]);

        $this->start($parentFlowId, 'slot-1');

        $parent = FlowSession::query()->whereNull('parent_session_id')->sole();
        $child  = FlowSession::query()->whereNotNull('parent_session_id')->sole();
        $this->assertSame(FlowSessionStatus::PausedSubflow, $parent->status);
        $this->assertSame(FlowSessionStatus::Active, $child->status);
        $this->assertSame('k3', $child->current_node_id);
        $this->assertNull($child->fire_key);
        $this->assertSame($this->triggerId, $child->trigger_id);

        $continuations = $this->drain();

        // Both continuations name the run's root; the first runs the child, the second the parent it handed back to.
        $this->assertSame(
            [(string) $parent->getKey(), (string) $parent->getKey()],
            array_map(static fn (ContinueAutomationRunJob $job): string => $job->sessionId, $continuations),
        );
        $this->assertSame(FlowSessionStatus::Ended, $child->refresh()->status);
        $this->assertSame(FlowSessionStatus::Ended, $parent->refresh()->status);
        $this->assertSame(['k1' => 1, 'k2' => 1, 'k3' => 1, 'p1' => 1], AutomationProbeTestHandler::$runs);
    }

    #[DataProvider('subflowInLoopSegments')]
    public function test_a_subflow_inside_a_loop_body_runs_each_iteration_once_with_its_true_count(int $segmentSteps, int $childProbes): void
    {
        // An iteration is the loop node, p1, the subflow node and the loop_end: four steps, the whole budget of one
        // iteration. A parent resumed by its child's end runs the next iterations in a nested call; the frame that ran
        // the subflow node must not write its stale iteration count over theirs, or a later iteration — in this
        // segment or the next — starts from a count it never ran and fails as a runaway.
        config([
            'flow.execution.max_iterations'      => 4,
            'flow.execution.max_loop_iterations' => 10,
            'flow.automation.segment_steps'      => $segmentSteps,
        ]);
        $parentFlowId = $this->subflowInLoopParent($this->childOfProbes($childProbes), 6);

        $this->start($parentFlowId, 'slot-1');
        $this->drain();

        $parent = FlowSession::query()->whereNull('parent_session_id')->sole();
        $this->assertSame(FlowSessionStatus::Ended, $parent->status);
        $this->assertSame(6, FlowSession::query()->whereNotNull('parent_session_id')->where('status', FlowSessionStatus::Ended)->count());

        $expectedRuns = ['p1' => 6];

        for ($probe = 1; $probe <= $childProbes; ++$probe) {
            $expectedRuns["k{$probe}"] = 6;
        }

        $this->assertEqualsCanonicalizing($expectedRuns, AutomationProbeTestHandler::$runs);
        // Entering the loop and the end node ran outside its body; 6 x (p1, subflow, loop_end, loop) inside it.
        $this->assertSame(2, $parent->step_count);
        $this->assertSame(24, $parent->loop_step_count);
        $this->assertSame(0, $parent->loop_iteration_step_count);
    }

    public function test_a_subflow_step_whose_child_ran_out_still_counts_in_its_iteration(): void
    {
        // The segment runs out inside the child: no nested run took the parent further, so the subflow step's own
        // frame is the one that records it in the iteration count the next segment goes on from.
        config(['flow.automation.segment_steps' => 3]);
        $parentFlowId = $this->subflowInLoopParent($this->childOfProbes(3), 2);

        $this->start($parentFlowId, 'slot-1');

        $parent = FlowSession::query()->whereNull('parent_session_id')->sole();
        $this->assertSame(FlowSessionStatus::PausedSubflow, $parent->status);
        $this->assertSame('p-sub', $parent->current_node_id);
        // p1 and the subflow node of the first iteration; entering the loop counts outside it.
        $this->assertSame(2, $parent->loop_iteration_step_count);
        $this->assertSame(1, $parent->step_count);
        $this->assertSame(2, $parent->loop_step_count);

        $this->drain();

        $this->assertSame(FlowSessionStatus::Ended, $parent->refresh()->status);
        $this->assertSame(['p1' => 2, 'k1' => 2, 'k2' => 2, 'k3' => 2], AutomationProbeTestHandler::$runs);
        $this->assertSame(0, $parent->loop_iteration_step_count);
    }

    public function test_a_start_killed_inside_its_subflow_child_is_retried_into_the_child(): void
    {
        config(['flow.automation.segment_steps' => 50]);
        $childFlowId = $this->flow([
            ['id' => 'k1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => ['action' => 'lose_lock_once']],
            ['id' => 'k2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'k-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);
        $parentFlowId = $this->subflowParent($childFlowId);

        try {
            $this->start($parentFlowId, 'slot-1');
            $this->fail('The lost lock inside the child must abort the start.');
        } catch (SessionLockLostException) {
        }

        $parent = FlowSession::query()->whereNull('parent_session_id')->sole();
        $child  = FlowSession::query()->whereNotNull('parent_session_id')->sole();
        $this->assertSame(FlowSessionStatus::PausedSubflow, $parent->status);
        $this->assertSame(FlowSessionStatus::Active, $child->status);

        $this->start($parentFlowId, 'slot-1');

        $this->assertSame(FlowSessionStatus::Ended, $child->refresh()->status);
        $this->assertSame(FlowSessionStatus::Ended, $parent->refresh()->status);
        $this->assertSame(['k1' => 1, 'k2' => 1, 'p1' => 1], AutomationProbeTestHandler::$runs);
    }

    public function test_a_continuation_killed_inside_the_parent_its_child_resumed_is_retried_into_the_parent(): void
    {
        config(['flow.automation.segment_steps' => 3]);
        $childFlowId = $this->flow([
            ['id' => 'k1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'k2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'k3', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'k-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);
        $parentFlowId = $this->flow([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
            ['id' => 'p1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => ['action' => 'lose_lock_once']],
            ['id' => 'p2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'pe1', 'from' => 'p-sub', 'to' => 'p1', 'handle' => 'success'],
            ['id' => 'pe2', 'from' => 'p1', 'to' => 'p2', 'handle' => 'default'],
            ['id' => 'pe3', 'from' => 'p2', 'to' => 'p-end', 'handle' => 'default'],
        ]);

        // The start runs k1–k3 in the child; its continuation ends the child and resumes the parent inside it.
        $this->start($parentFlowId, 'slot-1');
        $continuation = $this->pushedContinuations()[0];

        try {
            $this->app->call([$continuation, 'handle']);
            $this->fail('The lost lock inside the resumed parent must abort the continuation.');
        } catch (SessionLockLostException) {
        }

        $parent = FlowSession::query()->whereNull('parent_session_id')->sole();
        $this->assertSame(FlowSessionStatus::Active, $parent->status);
        $this->assertSame('p2', $parent->current_node_id);

        $this->app->call([$continuation, 'handle']);

        $this->assertSame(FlowSessionStatus::Ended, $parent->refresh()->status);
        $this->assertSame(['k1' => 1, 'k2' => 1, 'k3' => 1, 'p1' => 1, 'p2' => 1], AutomationProbeTestHandler::$runs);
    }

    public function test_a_run_created_past_the_lock_is_read_back_and_continued_not_started_twice(): void
    {
        config(['flow.automation.segment_steps' => 50]);
        $flowId     = $this->probeChain(2);
        $definition = FlowDefinition::query()->where('flow_id', $flowId)->sole();
        $real       = $this->app->make(FlowSessionRepositoryInterface::class);
        $triggerId  = $this->triggerId;

        // Another worker inserts the run between this start's lookup and its insert (its lock TTL lapsed).
        $this->app->instance(FlowSessionRepositoryInterface::class, new class ($real, $definition, $triggerId) implements FlowSessionRepositoryInterface {
            private bool $raced = false;

            public function __construct(
                private readonly FlowSessionRepositoryInterface $real,
                private readonly FlowDefinition $definition,
                private readonly string $triggerId,
            ) {
            }

            public function findActiveForContact(Contact $contact, string $assistantId): ?FlowSession
            {
                return $this->real->findActiveForContact($contact, $assistantId);
            }

            public function findById(string $sessionId): ?FlowSession
            {
                return $this->real->findById($sessionId);
            }

            public function findByFireKey(string $triggerId, string $fireKey): ?FlowSession
            {
                if (! $this->raced) {
                    $this->raced = true;
                    $this->real->create([
                        'tenant_id'          => $this->definition->tenant_id,
                        'flow_definition_id' => $this->definition->getKey(),
                        'flow_version'       => 1,
                        'current_node_id'    => 'c1',
                        'state'              => [],
                        'status'             => FlowSessionStatus::Active,
                        'run_kind'           => FlowRunKind::Automation,
                        'trigger_id'         => $this->triggerId,
                        'fire_key'           => $fireKey,
                        'version'            => 1,
                    ]);

                    return null;
                }

                return $this->real->findByFireKey($triggerId, $fireKey);
            }

            public function findByStartKey(string $startKey): ?FlowSession
            {
                return $this->real->findByStartKey($startKey);
            }

            public function create(array $attributes): FlowSession
            {
                return $this->real->create($attributes);
            }

            public function cancel(FlowSession $session): void
            {
                $this->real->cancel($session);
            }
        });

        $this->start($flowId, 'slot-1');

        $this->assertSame(1, FlowSession::query()->count());
        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->sole()->status);
        $this->assertSame(['c1' => 1, 'c2' => 1], AutomationProbeTestHandler::$runs);
    }

    public function test_a_delay_wake_up_killed_after_moving_the_run_on_is_retried_into_a_continuation(): void
    {
        config(['flow.automation.segment_steps' => 50]);
        $flowId = $this->flow([
            ['id' => 'd1', 'type' => 'delay', 'version' => 1, 'config' => ['seconds' => 60]],
            ['id' => 'c1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => ['action' => 'lose_lock_once']],
            ['id' => 'c2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);

        $this->start($flowId, 'slot-1');
        $wakeUp = Queue::pushed(ResumeDelayedFlowSessionJob::class)->sole();
        $this->travel(61)->seconds();

        try {
            $this->app->call([$wakeUp, 'handle']);
            $this->fail('The lost lock must abort the wake-up.');
        } catch (SessionLockLostException) {
        }

        $this->assertSame('c2', FlowSession::query()->sole()->current_node_id);

        // The retry finds the run moved past the delay and hands it to the automation queue.
        $this->app->call([$wakeUp, 'handle']);
        $this->assertCount(1, $this->pushedContinuations());
        $this->drain();

        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->sole()->status);
        $this->assertSame(['c1' => 1, 'c2' => 1], AutomationProbeTestHandler::$runs);
    }

    public function test_a_parent_the_sweeper_resumed_and_lost_is_handed_to_the_automation_queue(): void
    {
        config(['flow.automation.segment_steps' => 50]);
        $childFlowId = $this->flow([
            ['id' => 'k1', 'type' => 'input', 'version' => 1, 'config' => []],
        ]);
        $parentFlowId = $this->flow([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
            ['id' => 'p1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => ['action' => 'lose_lock_once']],
            ['id' => 'p2', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'pe1', 'from' => 'p-sub', 'to' => 'p1', 'handle' => 'failed'],
            ['id' => 'pe2', 'from' => 'p1', 'to' => 'p2', 'handle' => 'default'],
            ['id' => 'pe3', 'from' => 'p2', 'to' => 'p-end', 'handle' => 'default'],
        ]);
        $definition = FlowDefinition::query()->where('flow_id', $parentFlowId)->sole();
        $parent     = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'p-sub',
            'state'              => [],
            'status'             => FlowSessionStatus::PausedSubflow,
            'run_kind'           => FlowRunKind::Automation,
            'trigger_id'         => $this->triggerId,
            'fire_key'           => 'slot-1',
            'version'            => 1,
            'expires_at'         => Carbon::now()->subMinute(),
        ]);
        FlowSession::query()->create([
            'tenant_id'             => self::TENANT_ID,
            'flow_definition_id'    => FlowDefinition::query()->where('flow_id', $childFlowId)->sole()->getKey(),
            'flow_version'          => 1,
            'current_node_id'       => 'k1',
            'state'                 => [],
            'status'                => FlowSessionStatus::WaitingInput,
            'run_kind'              => FlowRunKind::Automation,
            'trigger_id'            => $this->triggerId,
            'parent_session_id'     => $parent->getKey(),
            'parent_resume_node_id' => 'p-sub',
            'version'               => 1,
        ]);

        $this->app->make(SubflowTimeoutSweeper::class)->sweep();

        $this->assertSame(FlowSessionStatus::Active, $parent->refresh()->status);
        $this->assertSame('p2', $parent->current_node_id);
        $this->assertCount(1, $this->pushedContinuations());

        $this->drain();

        $this->assertSame(FlowSessionStatus::Ended, $parent->refresh()->status);
        $this->assertSame(['p1' => 1, 'p2' => 1], AutomationProbeTestHandler::$runs);
    }

    public function test_a_run_gets_its_deadline_at_its_start_and_its_subflow_child_carries_the_same(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');
        $childFlowId = $this->probeChain(3);

        $this->start($this->subflowParent($childFlowId), 'slot-1');

        $parent = FlowSession::query()->whereNull('parent_session_id')->sole();
        $child  = FlowSession::query()->whereNotNull('parent_session_id')->sole();
        $this->assertSame('2026-11-20 12:00:00', $parent->deadline_at?->toDateTimeString(), 'The start plus 24 hours.');
        $this->assertSame('2026-11-20 12:00:00', $child->deadline_at?->toDateTimeString(), 'A child lives within its run.');

        config(['flow.automation.max_run_hours' => 1]);
        Carbon::setTestNow('2026-11-19 12:30:00');
        $this->drain();

        $this->assertSame(FlowSessionStatus::Ended, $parent->refresh()->status);
        $this->assertSame('2026-11-20 12:00:00', $parent->deadline_at?->toDateTimeString(), 'A setting change never moves a running deadline.');
    }

    public function test_the_deadline_setting_is_held_within_its_bounds(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');
        config(['flow.automation.max_run_hours' => 0]);

        $this->start($this->probeChain(1), 'slot-1');

        $this->assertSame('2026-11-19 13:00:00', FlowSession::query()->sole()->deadline_at?->toDateTimeString());
    }

    public function test_a_conversation_is_not_segmented(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);
        $definition = FlowDefinition::query()->where('flow_id', $this->probeChain(5))->sole();

        $session = $this->app->make(FlowEngineInterface::class)->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(0, $session->refresh()->step_count);
        $this->assertNull($session->deadline_at, 'A conversation has no deadline.');
        Queue::assertNotPushed(ContinueAutomationRunJob::class);
    }

    public function test_a_flow_without_an_active_definition_starts_nothing(): void
    {
        $this->start((string) Str::uuid(), 'slot-1');

        $this->assertSame(0, FlowSession::query()->count());
    }

    public function test_a_fire_key_is_unique_within_its_trigger(): void
    {
        $definition = FlowDefinition::query()->where('flow_id', $this->probeChain(1))->sole();
        $row        = [
            'tenant_id'          => self::TENANT_ID,
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'c1',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'run_kind'           => FlowRunKind::Automation,
            'trigger_id'         => $this->triggerId,
            'fire_key'           => 'slot-1',
            'version'            => 1,
        ];
        FlowSession::query()->create($row);

        $this->expectException(UniqueConstraintViolationException::class);

        FlowSession::query()->create($row);
    }

    public function test_a_fire_key_needs_its_trigger(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FlowRunOrigin::automation(fireKey: 'slot-1');
    }

    public function test_a_fire_key_fits_its_column(): void
    {
        $this->assertSame(
            str_repeat('k', FlowRunOrigin::MAX_FIRE_KEY_LENGTH),
            FlowRunOrigin::automation($this->triggerId, null, str_repeat('k', FlowRunOrigin::MAX_FIRE_KEY_LENGTH))->fireKey,
        );

        $this->expectException(InvalidArgumentException::class);

        FlowRunOrigin::automation($this->triggerId, null, str_repeat('k', FlowRunOrigin::MAX_FIRE_KEY_LENGTH + 1));
    }

    public function test_a_stopped_tenant_drops_a_start_and_postpones_a_continuation(): void
    {
        $start        = new StartAutomationRunJob(self::TENANT_ID, 'flow', $this->triggerId, 'slot-1');
        $continuation = new ContinueAutomationRunJob(self::TENANT_ID, 'session', 'segment');

        $this->assertSame('Drop', $this->stoppedTenantAction($start));
        $this->assertSame('Postpone', $this->stoppedTenantAction($continuation));
    }

    private function start(string $flowId, string $fireKey): void
    {
        $job = new StartAutomationRunJob(self::TENANT_ID, $flowId, $this->triggerId, $fireKey);

        $this->assertSame(AutomationRunner::QUEUE, $job->queue);
        $this->app->call([$job, 'handle']);
    }

    /**
     * Run every continuation queued so far and every one they queue in turn.
     *
     * @return list<ContinueAutomationRunJob> the continuations run, in order
     */
    private function drain(): array
    {
        $run = [];

        while (null !== ($job = $this->pushedContinuations()[$this->handled] ?? null)) {
            ++$this->handled;
            $run[] = $job;
            $this->app->call([$job, 'handle']);
        }

        return $run;
    }

    /**
     * @return list<ContinueAutomationRunJob>
     */
    private function pushedContinuations(): array
    {
        return Queue::pushed(ContinueAutomationRunJob::class)->values()->all();
    }

    /**
     * A flow of {@code $probes} probe nodes in a row, then an end node.
     */
    private function probeChain(int $probes): string
    {
        $nodes = [];

        for ($i = 1; $i <= $probes; ++$i) {
            $nodes[] = ['id' => "c{$i}", 'type' => 'automation_probe_test', 'version' => 1, 'config' => []];
        }

        $nodes[] = ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']];

        return $this->flow($nodes);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>|null  $edges  null links the nodes in a row through `default`
     */
    private function flow(array $nodes, ?array $edges = null): string
    {
        if (null === $edges) {
            $edges = [];

            for ($i = 0; $i < count($nodes) - 1; ++$i) {
                $edges[] = ['id' => "e{$i}", 'from' => $nodes[$i]['id'], 'to' => $nodes[$i + 1]['id'], 'handle' => 'default'];
            }
        }

        $flowId = (string) Str::uuid();

        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Automation',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);

        return $flowId;
    }

    /**
     * A parent that runs {@code $childFlowId} as a subflow, then one probe node `p1`, then ends.
     */
    private function subflowParent(string $childFlowId): string
    {
        return $this->flow([
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
            ['id' => 'p1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'pe1', 'from' => 'p-sub', 'to' => 'p1', 'handle' => 'success'],
            ['id' => 'pe2', 'from' => 'p1', 'to' => 'p-end', 'handle' => 'default'],
        ]);
    }

    /**
     * A child flow of {@code $probes} probe nodes `k1`, `k2`, … in a row, then an end node.
     */
    private function childOfProbes(int $probes): string
    {
        $nodes = [];

        for ($i = 1; $i <= $probes; ++$i) {
            $nodes[] = ['id' => "k{$i}", 'type' => 'automation_probe_test', 'version' => 1, 'config' => []];
        }

        $nodes[] = ['id' => 'k-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']];

        return $this->flow($nodes);
    }

    /**
     * A parent whose counted loop of {@code $iterations} runs a probe `p1` and then {@code $childFlowId} as a subflow in
     * its body, then ends.
     */
    private function subflowInLoopParent(string $childFlowId, int $iterations): string
    {
        return $this->flow([
            ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => [
                'mode'          => 'counted',
                'iterator_name' => 'i',
                'count_source'  => ['type' => 'literal', 'value' => $iterations],
            ]],
            ['id' => 'p1', 'type' => 'automation_probe_test', 'version' => 1, 'config' => []],
            ['id' => 'p-sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
            ['id' => 'le', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 'loop', 'iterator_name' => 'i']],
            ['id' => 'p-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ], [
            ['id' => 'pe1', 'from' => 'loop', 'to' => 'p1', 'handle' => 'loop'],
            ['id' => 'pe2', 'from' => 'p1', 'to' => 'p-sub', 'handle' => 'default'],
            ['id' => 'pe3', 'from' => 'p-sub', 'to' => 'le', 'handle' => 'success'],
            ['id' => 'pe4', 'from' => 'loop', 'to' => 'p-end', 'handle' => 'default'],
        ]);
    }

    private function stoppedTenantAction(StartAutomationRunJob|ContinueAutomationRunJob $job): string
    {
        $middleware = $job->middleware()[0];
        $this->assertInstanceOf(RespectsTenantAccessMode::class, $middleware);

        return (new ReflectionProperty($middleware, 'whenStopped'))->getValue($middleware)->name;
    }
}

/**
 * A node with a counted side effect. `lose_lock_once` deletes the run's lock key the first time, as if its TTL lapsed
 * while the worker hung: the engine's heartbeat before the next node aborts the job, which is what a killed worker
 * leaves behind — a session `active` at its saved position. `take_6_seconds` moves the clock on.
 */
final class AutomationProbeTestHandler implements NodeHandlerInterface
{
    /** @var array<string, int> */
    public static array $runs = [];

    /** @var array<string, true> */
    private static array $lostLock = [];

    public static function reset(): void
    {
        self::$runs     = [];
        self::$lostLock = [];
    }

    public function type(): string
    {
        return 'automation_probe_test';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Automation probe';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        self::$runs[$context->nodeId] = (self::$runs[$context->nodeId] ?? 0) + 1;

        $action = $nodeConfig['config']['action'] ?? null;

        if ('lose_lock_once' === $action && ! isset(self::$lostLock[$context->nodeId])) {
            self::$lostLock[$context->nodeId] = true;
            $handle                           = app(SessionLockRegistry::class)->current();
            Redis::connection()->del($handle?->key ?? '');
        }

        if ('take_6_seconds' === $action) {
            Carbon::setTestNow(Carbon::now()->addSeconds(6));
        }

        if ('take_31_seconds' === $action) {
            Carbon::setTestNow(Carbon::now()->addSeconds(31));
        }

        return NodeExecutionResult::executed();
    }
}
