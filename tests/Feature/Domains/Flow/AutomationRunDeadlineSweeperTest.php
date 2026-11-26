<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Enums\FlowRunFailureReason;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\AutomationRunDeadline;
use App\Domains\Flow\Orchestration\AutomationRunDeadlineSweeper;
use App\Domains\Flow\Orchestration\AutomationRunner;
use App\Domains\Flow\Orchestration\DelayedSessionResumer;
use App\Domains\Flow\Retention\AutomationRunRetention;
use App\Domains\Flow\Services\FlowDraftService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Carbon\CarbonImmutable;
use Closure;
use Fapost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use Fapost\Foundation\Analytics\DTO\AnalyticsEvent;
use Fapost\Foundation\Analytics\Enums\AnalyticsEventType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\FeatureTestCase;

/**
 * The deadline sweeper fails a whole automation run that is still live past its deadline, or still `pending` past
 * the lost-start threshold, and leaves everything else alone: conversations, runs on time, finished sessions,
 * another tenant's runs. Nothing it fails runs again.
 */
final class AutomationRunDeadlineSweeperTest extends FeatureTestCase
{
    private string $tenantId;

    private CarbonImmutable $now;

    private FlowDefinition $definition;

    /** @var list<AnalyticsEvent> */
    private array $analytics = [];

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->now = CarbonImmutable::parse('2026-11-19 12:00:00');
        $this->travelTo($this->now);
        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));
        $this->definition = $this->definition();

        $events = &$this->analytics;
        $this->app->instance(AnalyticsWriterInterface::class, new class ($events) implements AnalyticsWriterInterface {
            /** @param list<AnalyticsEvent> $events */
            public function __construct(private array &$events)
            {
            }

            public function record(AnalyticsEvent $event): void
            {
                $this->events[] = $event;
            }
        });
    }

    public function test_an_overdue_run_is_failed_with_its_reason_and_no_continuation_is_left_valid(): void
    {
        Log::spy();
        $run = $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->subMinute(), 'segment_id' => (string) Str::uuid()]);

        $report = $this->sweep();

        $this->assertSame(['failed_runs' => 1, 'failed_sessions' => 1, 'skipped_busy' => 0], $report);
        $run->refresh();
        $this->assertSame(FlowSessionStatus::Failed, $run->status);
        $this->assertSame(FlowRunFailureReason::DeadlineExceeded, $run->failure_reason);
        $this->assertSame(2, $run->version);
        $this->assertTrue($run->updated_at->equalTo($this->now));
        $this->assertNull($run->segment_id);
        $this->assertSame('n1', $run->current_node_id, 'Where the run stopped stays visible.');
        $this->assertCount(1, $this->analytics);
        $this->assertSame(AnalyticsEventType::FlowFailed, $this->analytics[0]->eventType);
        $this->assertSame(['session_id' => (string) $run->getKey(), 'reason' => 'deadline_exceeded'], $this->analytics[0]->payload);
        Log::shouldHaveReceived('warning')->with('flow.automation.run_overdue_failed', Mockery::on(
            fn (array $context): bool => (string) $run->getKey() === $context['root_id']
                && $run->trigger_id === $context['trigger_id']
                && 'deadline_exceeded' === $context['reason']
                && $this->now->subMinute()->toIso8601String() === $context['deadline_at']
                && 1 === $context['sessions'],
        ))->once();
        Queue::assertNothingPushed();
    }

    public function test_a_whole_chain_is_failed_bottom_up_and_its_finished_sessions_stay_as_they_are(): void
    {
        $deadline   = $this->now->subMinute();
        $root       = $this->automation(FlowSessionStatus::PausedSubflow, ['deadline_at' => $deadline]);
        $finished   = $this->automation(FlowSessionStatus::Ended, ['deadline_at' => $deadline, 'parent_session_id' => $root->getKey()]);
        $child      = $this->automation(FlowSessionStatus::Active, ['deadline_at' => $deadline, 'parent_session_id' => $root->getKey()]);
        $grandchild = $this->automation(FlowSessionStatus::Paused, ['deadline_at' => $deadline, 'parent_session_id' => $child->getKey()]);

        $report = $this->sweep();

        $this->assertSame(['failed_runs' => 1, 'failed_sessions' => 3, 'skipped_busy' => 0], $report);

        foreach ([$root, $child, $grandchild] as $session) {
            $session->refresh();
            $this->assertSame(FlowSessionStatus::Failed, $session->status);
            $this->assertSame(FlowRunFailureReason::DeadlineExceeded, $session->failure_reason);
            $this->assertSame('n1', $session->current_node_id, 'No node ran and no parent took its failed handle.');
        }

        $finished->refresh();
        $this->assertSame(FlowSessionStatus::Ended, $finished->status);
        $this->assertNull($finished->failure_reason);
        $this->assertSame(1, $finished->version);
        $this->assertSame(
            [(string) $grandchild->getKey(), (string) $child->getKey(), (string) $root->getKey()],
            array_map(static fn (AnalyticsEvent $event): string => $event->payload['session_id'], $this->analytics),
            'Bottom-up.',
        );
        Queue::assertNothingPushed();
    }

    public function test_a_parent_left_waiting_on_a_child_that_already_ended_is_failed_past_the_deadline(): void
    {
        $root  = $this->automation(FlowSessionStatus::PausedSubflow, ['deadline_at' => $this->now->subMinute()]);
        $child = $this->automation(FlowSessionStatus::Ended, ['deadline_at' => $this->now->subMinute(), 'parent_session_id' => $root->getKey()]);

        $this->sweep();

        $this->assertSame(FlowSessionStatus::Failed, $root->refresh()->status);
        $this->assertSame(FlowSessionStatus::Ended, $child->refresh()->status);
    }

    public function test_a_pending_run_is_failed_as_a_lost_start_only_past_the_threshold(): void
    {
        $young = $this->automation(FlowSessionStatus::Pending, ['deadline_at' => $this->now->addHours(22), 'created_at' => $this->now->subHours(2)]);
        $lost  = $this->automation(FlowSessionStatus::Pending, ['deadline_at' => $this->now->addHours(20), 'created_at' => $this->now->subHours(3)->subSecond()]);

        $this->sweep();

        $this->assertSame(FlowSessionStatus::Pending, $young->refresh()->status);
        $this->assertSame(FlowSessionStatus::Failed, $lost->refresh()->status);
        $this->assertSame(FlowRunFailureReason::StartLost, $lost->failure_reason);
    }

    public function test_a_pending_run_past_its_deadline_is_a_lost_start_too(): void
    {
        $lost = $this->automation(FlowSessionStatus::Pending, ['deadline_at' => $this->now->subMinute(), 'created_at' => $this->now->subMinutes(30)]);

        $this->sweep();

        $this->assertSame(FlowRunFailureReason::StartLost, $lost->refresh()->failure_reason);
    }

    public function test_a_run_without_a_deadline_is_measured_from_its_creation(): void
    {
        Log::spy();
        $old   = $this->automation(FlowSessionStatus::Active, ['deadline_at' => null, 'created_at' => $this->now->subHours(24)->subSecond()]);
        $young = $this->automation(FlowSessionStatus::Active, ['deadline_at' => null, 'created_at' => $this->now->subHours(23)]);

        $this->sweep();

        $this->assertSame(FlowSessionStatus::Failed, $old->refresh()->status);
        $this->assertSame(FlowSessionStatus::Active, $young->refresh()->status);
        Log::shouldHaveReceived('warning')->with('flow.automation.run_overdue_failed', Mockery::on(
            fn (array $context): bool => (string) $old->getKey() === $context['root_id']
                && $this->now->subSecond()->toIso8601String() === $context['deadline_at'],
        ))->once();
    }

    public function test_the_fallback_follows_the_setting(): void
    {
        config(['flow.automation.max_run_hours' => 2]);
        $run = $this->automation(FlowSessionStatus::WaitingInput, ['deadline_at' => null, 'created_at' => $this->now->subHours(3)]);

        $this->sweep();

        $this->assertSame(FlowSessionStatus::Failed, $run->refresh()->status);
    }

    public function test_conversations_runs_on_time_finished_runs_and_other_tenants_are_never_touched(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $contact   = Contact::factory()->forTenant($this->tenantId)->create();
        $kept      = [];

        foreach (AutomationRunRetention::LIVE as $status) {
            $kept[] = $this->flowSession($status, [
                'run_kind'     => FlowRunKind::Conversation,
                'contact_id'   => $contact->getKey(),
                'assistant_id' => $assistant->getKey(),
                'trigger_id'   => null,
                'created_at'   => $this->now->subDays(400),
                'deadline_at'  => $this->now->subDays(399),
            ]);
            $kept[] = $this->automation($status, ['deadline_at' => $this->now->addMinute(), 'created_at' => $this->now->subMinutes(30)]);
            $kept[] = $this->automation($status, ['deadline_at' => $this->now->subDay(), 'tenant_id' => (string) Str::uuid()]);
        }

        foreach (AutomationRunRetention::TERMINAL as $status) {
            $kept[] = $this->automation($status, ['deadline_at' => $this->now->subDay(), 'created_at' => $this->now->subDays(2)]);
        }

        $before = array_map(static fn (FlowSession $session): array => [$session->status, $session->version], $kept);

        $report = $this->sweep();

        $this->assertSame(['failed_runs' => 0, 'failed_sessions' => 0, 'skipped_busy' => 0], $report);
        $this->assertSame($before, array_map(static fn (FlowSession $session): array => [
            $session->refresh()->status,
            $session->version,
        ], $kept));
    }

    public function test_a_row_of_another_tenant_under_a_run_is_not_part_of_it_and_is_left_alone(): void
    {
        $otherTenantId = (string) Str::uuid();
        $root          = $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->subMinute()]);
        $foreign       = $this->automation(FlowSessionStatus::Active, [
            'tenant_id'         => $otherTenantId,
            'deadline_at'       => $this->now->subMinute(),
            'parent_session_id' => $root->getKey(),
        ]);
        // A live, overdue row of this tenant below the foreign one: only the walk's tenant filter keeps it out of the run.
        $foreignChild = $this->automation(FlowSessionStatus::Paused, [
            'deadline_at'       => $this->now->subMinute(),
            'parent_session_id' => $foreign->getKey(),
        ]);

        $this->assertSame($otherTenantId, FlowSession::query()->whereKey($foreign->getKey())->value('tenant_id'));
        $this->assertSame((string) $root->tenant_id, (string) FlowSession::query()->whereKey($foreignChild->getKey())->value('tenant_id'));

        $report = $this->sweep();

        $this->assertSame(['failed_runs' => 1, 'failed_sessions' => 1, 'skipped_busy' => 0], $report);
        $this->assertSame(FlowSessionStatus::Failed, $root->refresh()->status);

        foreach ([$foreign, $foreignChild] as $row) {
            $row->refresh();
            $this->assertNotSame(FlowSessionStatus::Failed, $row->status);
            $this->assertSame(1, $row->version);
        }
    }

    public function test_a_run_whose_lock_is_busy_is_skipped_and_the_others_are_still_swept(): void
    {
        Log::spy();
        $busy = $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->subMinute()]);
        $free = $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->subMinute()]);
        $this->guardBusyFor($busy);

        $report = $this->sweep();

        $this->assertSame(['failed_runs' => 1, 'failed_sessions' => 1, 'skipped_busy' => 1], $report);
        $this->assertSame(FlowSessionStatus::Active, $busy->refresh()->status);
        $this->assertSame(FlowSessionStatus::Failed, $free->refresh()->status);
        Log::shouldHaveReceived('info')->with('flow.automation.run_overdue_lock_busy', Mockery::on(
            fn (array $context): bool => (string) $busy->getKey() === $context['root_id'],
        ))->once();
    }

    public function test_a_run_that_cannot_be_handled_is_logged_and_the_pass_goes_on(): void
    {
        Log::spy();
        $broken = $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->subMinute(), 'trigger_id' => null]);
        $run    = $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->subMinute()]);

        $this->sweep();

        $this->assertSame(FlowSessionStatus::Active, $broken->refresh()->status);
        $this->assertSame(FlowSessionStatus::Failed, $run->refresh()->status);
        Log::shouldHaveReceived('warning')->with('flow.automation.run_overdue_skipped', Mockery::on(
            fn (array $context): bool => (string) $broken->getKey() === $context['root_id'],
        ))->once();
    }

    public function test_a_conflicting_write_rolls_the_whole_run_back_for_the_next_pass(): void
    {
        Log::spy();
        $root  = $this->automation(FlowSessionStatus::PausedSubflow, ['deadline_at' => $this->now->subMinute()]);
        $child = $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->subMinute(), 'parent_session_id' => $root->getKey()]);

        // Another worker writes the root between the sweeper's read and its write.
        FlowSession::updated(static function (FlowSession $session) use ($child, $root): void {
            if ((string) $session->getKey() === (string) $child->getKey()) {
                FlowSession::query()->whereKey($root->getKey())->increment('version');
            }
        });

        $report = $this->sweep();

        $this->assertSame(0, $report['failed_runs']);
        $this->assertSame(FlowSessionStatus::Active, $child->refresh()->status);
        $this->assertNull($child->failure_reason);
        $this->assertSame(FlowSessionStatus::PausedSubflow, $root->refresh()->status);
        $this->assertSame([], $this->analytics);
        Log::shouldHaveReceived('info')->with('flow.automation.run_overdue_conflict', Mockery::on(
            fn (array $context): bool => (string) $root->getKey() === $context['root_id'],
        ))->once();
        Log::shouldNotHaveReceived('warning', ['flow.automation.run_overdue_skipped', Mockery::any()]);
    }

    public function test_the_query_and_the_re_check_under_the_lock_agree_on_what_is_overdue(): void
    {
        $sessions = [];

        foreach ([-1, 0, 1] as $offset) {
            $sessions[] = $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->addSeconds($offset)]);
            $sessions[] = $this->automation(FlowSessionStatus::Active, [
                'deadline_at' => null,
                'created_at'  => $this->now->subHours(24)->addSeconds($offset),
            ]);
            $sessions[] = $this->automation(FlowSessionStatus::Pending, [
                'deadline_at' => $this->now->addHours(20),
                'created_at'  => $this->now->subSeconds(AutomationRunDeadline::PENDING_LOST_AFTER_SECONDS)->addSeconds($offset),
            ]);
        }

        $deadline = $this->app->make(AutomationRunDeadline::class);
        $ids      = array_map(static fn (FlowSession $session): string => (string) $session->getKey(), $sessions);

        $selected = $deadline->whereOverdue(FlowSession::query()->whereIn('id', $ids), $this->now)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->sort()
            ->values()
            ->all();
        $rechecked = collect($sessions)
            ->filter(fn (FlowSession $session): bool => $deadline->isOverdue($session->refresh(), $this->now))
            ->map(static fn (FlowSession $session): string => (string) $session->getKey())
            ->sort()
            ->values()
            ->all();

        $this->assertCount(3, $selected, 'One second past each threshold is overdue; on it and before it is not.');
        $this->assertSame($selected, $rechecked);
    }

    public function test_nothing_runs_a_swept_run_again(): void
    {
        $segmentId = (string) Str::uuid();
        $run       = $this->automation(FlowSessionStatus::Active, [
            'deadline_at' => $this->now->subMinute(),
            'segment_id'  => $segmentId,
            'fire_key'    => 'slot-1',
        ]);
        $sleeping = $this->automation(FlowSessionStatus::Paused, ['deadline_at' => $this->now->subMinute()]);

        $this->sweep();

        $runner = $this->app->make(AutomationRunner::class);
        $runner->continue((string) $run->getKey(), $segmentId);
        $runner->start($this->tenantId, $this->definition->flow_id, (string) $run->trigger_id, 'slot-1');
        $this->app->make(DelayedSessionResumer::class)->resume((string) $sleeping->getKey(), 'n1', $this->now->subMinute());

        $this->assertSame(1, FlowSession::query()->where('trigger_id', $run->trigger_id)->count());
        $this->assertSame(FlowSessionStatus::Failed, $run->refresh()->status);
        $this->assertSame('n1', $run->current_node_id);
        $this->assertSame(2, $run->version);
        $this->assertSame(FlowSessionStatus::Failed, $sleeping->refresh()->status);
        $this->assertSame(2, $sleeping->version);
        Queue::assertNothingPushed();
    }

    public function test_a_swept_run_no_longer_keeps_its_flow_from_being_deleted(): void
    {
        $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->subMinute()]);
        $draft   = FlowDraft::factory()->make(['tenant_id' => $this->tenantId, 'flow_id' => $this->definition->flow_id]);
        $service = $this->app->make(FlowDraftService::class);

        $this->assertTrue($service->hasLiveSessions($draft));

        $this->sweep();

        $this->assertFalse($service->hasLiveSessions($draft));
    }

    /**
     * @return array{failed_runs: int, failed_sessions: int, skipped_busy: int}
     */
    private function sweep(): array
    {
        return $this->app->make(AutomationRunDeadlineSweeper::class)->sweep($this->tenantId);
    }

    /**
     * Every lock is free but {@code $busy}'s, which another worker holds.
     */
    private function guardBusyFor(FlowSession $busy): void
    {
        $busyKey = LockScope::ofSession($busy)->key();

        $this->app->instance(FlowExecutionGuardInterface::class, new class ($busyKey) implements FlowExecutionGuardInterface {
            public function __construct(private string $busyKey)
            {
            }

            public function run(string $tenantId, string $contactId, string $assistantId, Closure $callback): mixed
            {
                return $callback();
            }

            public function runInScope(LockScope $scope, Closure $callback): mixed
            {
                if ($scope->key() === $this->busyKey) {
                    throw new SessionLockTimeoutException($scope->key());
                }

                return $callback();
            }
        });
    }

    /**
     * A contactless automation session of its own trigger.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function automation(FlowSessionStatus $status, array $overrides = []): FlowSession
    {
        return $this->flowSession($status, [
            'run_kind'   => FlowRunKind::Automation,
            'trigger_id' => (string) Str::uuid(),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function flowSession(FlowSessionStatus $status, array $overrides): FlowSession
    {
        return FlowSession::query()->forceCreate([
            'tenant_id'          => $this->tenantId,
            'assistant_id'       => null,
            'contact_id'         => null,
            'flow_definition_id' => $this->definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'n1',
            'state'              => [],
            'status'             => $status,
            'version'            => 1,
            'created_at'         => $this->now->subHour(),
            ...$overrides,
        ]);
    }

    private function definition(): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Deadline',
            'nodes'     => [
                ['id' => 'n1', 'type' => 'delay', 'version' => 1, 'config' => ['duration' => 'PT1H']],
                ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges'     => [['id' => 'e1', 'from' => 'n1', 'to' => 'end', 'handle' => 'default']],
            'is_active' => true,
        ]);
    }
}
