<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Schedule;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowHasLiveSessionsException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Models\FlowTriggerState;
use App\Domains\Flow\Orchestration\AutomationRunner;
use App\Domains\Flow\Schedule\ScheduleTriggerFirer;
use App\Domains\Flow\Services\FlowDraftService;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Jobs\Flow\StartAutomationRunJob;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use Fapost\Foundation\Analytics\DTO\AnalyticsEvent;
use Fapost\Foundation\Analytics\Enums\AnalyticsEventType;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * Claiming a schedule trigger's due slot: the slot moves by compare-and-set and its run is created `pending` in the same
 * transaction, or the slot is skipped (previous run live, flow not live) or missed (claimed too late), and the start
 * job turns the `pending` run into a running one.
 */
#[Group('redis')]
final class ScheduleTriggerFirerTest extends FeatureTestCase
{
    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-11-20 08:30:00');

        $tenant         = $this->app->make(TenantRepositoryInterface::class)->findAllActive()[0];
        $this->tenantId = $tenant->getId();
        $this->app->make(TenantContextInterface::class)->set($tenant);

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_due_slot_creates_a_pending_run_and_queues_its_start_after_the_slot_moved(): void
    {
        [$draft, $triggerId] = $this->liveSchedule('0 9 * * *', 'Europe/Berlin');
        $this->assertSame('2026-11-21 08:00:00', $this->trigger()->next_run_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-11-21 08:00:00');
        $report = $this->fire();

        $this->assertSame(['fired' => 1, 'skipped' => 0, 'missed' => 0], $report);

        $trigger = $this->trigger();
        $this->assertSame('2026-11-22 08:00:00', $trigger->next_run_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-21 08:00:00', $trigger->last_run_at?->format('Y-m-d H:i:s'));

        $run = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Pending, $run->status);
        $this->assertSame(FlowRunKind::Automation, $run->run_kind);
        $this->assertSame($triggerId, $run->trigger_id);
        $this->assertSame('schedule:2026-11-21T08:00Z', $run->fire_key);
        $this->assertSame((string) $this->assistant->getKey(), $run->assistant_id);
        $this->assertNull($run->contact_id);
        $this->assertNotNull($run->deadline_at, 'A pending run carries its deadline, so the sweeper can find a lost start.');
        // jsonb does not keep key order, so the state is compared by content.
        $this->assertEquals([
            'slot'       => '2026-11-21T08:00:00+00:00',
            'local_time' => '2026-11-21T09:00:00+01:00',
            'timezone'   => 'Europe/Berlin',
            'cron'       => '0 9 * * *',
        ], $run->state['flow']['schedule']);

        Queue::assertPushed(StartAutomationRunJob::class, fn (StartAutomationRunJob $job): bool => $this->tenantId === $job->tenantId
                && $draft->flow_id === $job->flowId
                && $triggerId === $job->triggerId
                && 'schedule:2026-11-21T08:00Z' === $job->fireKey
                && AutomationRunner::QUEUE === $job->queue
                && crc32($triggerId) % 60 === $job->delay);
    }

    public function test_a_second_pass_finds_the_slot_taken_and_does_nothing(): void
    {
        $this->liveSchedule('0 9 * * *');
        Carbon::setTestNow('2026-11-20 09:00:00');

        $this->fire();
        $report = $this->fire();

        $this->assertSame(['fired' => 0, 'skipped' => 0, 'missed' => 0], $report);
        $this->assertSame(1, FlowSession::query()->count());
        Queue::assertPushed(StartAutomationRunJob::class, 1);
    }

    public function test_the_start_job_turns_the_pending_run_into_a_running_one_once(): void
    {
        $this->liveSchedule('0 9 * * *');
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->fire();

        $job = Queue::pushed(StartAutomationRunJob::class)->sole();
        $this->app->call([$job, 'handle']);
        $this->app->call([$job, 'handle']);

        $run = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Ended, $run->status);
        $this->assertSame('schedule:2026-11-20T09:00Z', $run->fire_key);
    }

    public function test_a_previous_run_still_going_skips_the_slot(): void
    {
        Log::spy();
        [, $triggerId] = $this->liveSchedule('0 9 * * *');
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->fire();

        // The run of 09:00 waits for something; the next day's slot comes while it still does.
        FlowSession::query()->sole()->forceFill(['status' => FlowSessionStatus::WaitingInput])->save();
        Carbon::setTestNow('2026-11-21 09:00:00');

        $report = $this->fire();

        $this->assertSame(['fired' => 0, 'skipped' => 1, 'missed' => 0], $report);
        $this->assertSame(1, FlowSession::query()->count());
        $this->assertSame('2026-11-22 09:00:00', $this->trigger()->next_run_at?->format('Y-m-d H:i:s'));
        Log::shouldHaveReceived('warning')->with('flow.schedule.tick_skipped', Mockery::on(
            fn (array $context): bool => $triggerId === $context['trigger_id']
                && ScheduleTriggerFirer::SKIP_PREVIOUS_RUN_LIVE === $context['reason']
                && '2026-11-21T09:00:00+00:00' === $context['slot'],
        ))->once();
    }

    public function test_a_finished_previous_run_does_not_skip_the_slot(): void
    {
        $this->liveSchedule('0 9 * * *');
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->fire();
        FlowSession::query()->sole()->forceFill(['status' => FlowSessionStatus::Failed])->save();

        Carbon::setTestNow('2026-11-21 09:00:00');

        $this->assertSame(['fired' => 1, 'skipped' => 0, 'missed' => 0], $this->fire());
        $this->assertSame(2, FlowSession::query()->count());
    }

    public function test_a_slot_claimed_too_late_is_missed_once_and_never_caught_up(): void
    {
        Log::spy();
        $this->liveSchedule('*/10 * * * *');
        $this->assertSame('2026-11-20 08:40:00', $this->trigger()->next_run_at?->format('Y-m-d H:i:s'));

        // Down from 08:40 to 10:05: nine slots passed.
        Carbon::setTestNow('2026-11-20 10:05:00');
        $report = $this->fire();

        $this->assertSame(['fired' => 0, 'skipped' => 0, 'missed' => 1], $report);
        $this->assertSame(0, FlowSession::query()->count());
        $this->assertSame('2026-11-20 10:10:00', $this->trigger()->next_run_at?->format('Y-m-d H:i:s'), 'The next slot counts from now.');
        Log::shouldHaveReceived('warning')->with('flow.schedule.tick_missed', Mockery::on(
            fn (array $context): bool => '2026-11-20T08:40:00+00:00' === $context['slot'] && 5100 === $context['late_seconds'],
        ))->once();
        Queue::assertNothingPushed();
    }

    public function test_a_slot_a_few_minutes_late_still_runs(): void
    {
        $this->liveSchedule('0 9 * * *');
        Carbon::setTestNow('2026-11-20 09:04:00');

        $this->assertSame(['fired' => 1, 'skipped' => 0, 'missed' => 0], $this->fire());
        $this->assertSame('schedule:2026-11-20T09:00Z', FlowSession::query()->sole()->fire_key);
    }

    public function test_a_flow_without_an_active_definition_or_without_its_draft_skips_the_slot(): void
    {
        Log::spy();
        [$draft] = $this->liveSchedule('0 9 * * *');
        FlowDefinition::query()->where('flow_id', $draft->flow_id)->update(['is_active' => false]);
        Carbon::setTestNow('2026-11-20 09:00:00');

        $this->assertSame(['fired' => 0, 'skipped' => 1, 'missed' => 0], $this->fire());
        $this->assertSame('2026-11-21 09:00:00', $this->trigger()->next_run_at?->format('Y-m-d H:i:s'), 'Publishing again can bring it back: the schedule keeps its next slot.');

        FlowDefinition::query()->where('flow_id', $draft->flow_id)->update(['is_active' => true]);
        $draft->delete();
        Carbon::setTestNow('2026-11-21 09:00:00');

        $this->assertSame(['fired' => 0, 'skipped' => 1, 'missed' => 0], $this->fire());
        $this->assertSame(0, FlowSession::query()->count());
        $this->assertNull($this->trigger()->next_run_at, 'Nobody can manage the flow any more: its schedule stops.');
        Log::shouldHaveReceived('warning')->with('flow.schedule.tick_skipped', Mockery::on(
            static fn (array $context): bool => ScheduleTriggerFirer::SKIP_FLOW_NOT_LIVE === $context['reason'],
        ))->twice();
    }

    public function test_the_schedule_of_a_deleted_assistant_stops_coming_due(): void
    {
        Log::spy();
        $this->liveSchedule('* * * * *');
        $this->assistant->delete();
        Carbon::setTestNow('2026-11-20 08:31:00');

        $this->assertSame(['fired' => 0, 'skipped' => 1, 'missed' => 0], $this->fire());
        $this->assertNull($this->trigger()->next_run_at);

        Carbon::setTestNow('2026-11-20 08:32:00');

        $this->assertSame(['fired' => 0, 'skipped' => 0, 'missed' => 0], $this->fire());
        $this->assertSame(0, FlowSession::query()->count());
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->with('flow.schedule.tick_skipped', Mockery::on(
            static fn (array $context): bool => ScheduleTriggerFirer::SKIP_FLOW_NOT_LIVE === $context['reason'],
        ))->once();
    }

    public function test_a_late_pass_runs_the_earliest_slot_and_logs_the_slots_it_passed_over(): void
    {
        Log::spy();
        [, $triggerId] = $this->liveSchedule('* * * * *');
        $this->assertSame('2026-11-20 08:31:00', $this->trigger()->next_run_at?->format('Y-m-d H:i:s'));

        // The pass of 08:31 overran: this one lands at 08:33:05, with 08:32 and 08:33 due as well.
        Carbon::setTestNow('2026-11-20 08:33:05');

        $this->assertSame(['fired' => 1, 'skipped' => 0, 'missed' => 0], $this->fire());
        $this->assertSame('schedule:2026-11-20T08:31Z', FlowSession::query()->sole()->fire_key);
        $this->assertSame('2026-11-20 08:34:00', $this->trigger()->next_run_at?->format('Y-m-d H:i:s'));
        Log::shouldHaveReceived('warning')->with('flow.schedule.tick_missed', Mockery::on(
            fn (array $context): bool => $triggerId === $context['trigger_id']
                && '2026-11-20T08:32:00+00:00' === $context['slot']
                && 2 === $context['missed_slots']
                && '2026-11-20T08:31:00+00:00' === $context['run_slot'],
        ))->once();
    }

    public function test_a_slot_another_pass_moved_after_this_one_read_it_is_not_claimed(): void
    {
        $this->liveSchedule('0 9 * * *');
        Carbon::setTestNow('2026-11-20 09:00:00');

        // Between this pass's read of the due triggers and its claim, another pass takes the slot.
        $moved = false;
        DB::listen(function (QueryExecuted $query) use (&$moved): void {
            if ($moved || ! str_starts_with(mb_strtolower($query->sql), 'select') || ! str_contains($query->sql, 'flow_triggers') || ! str_contains($query->sql, 'next_run_at')) {
                return;
            }

            $moved = true;
            FlowTrigger::query()->toBase()->update(['next_run_at' => '2026-11-21 09:00:00']);
        });

        $this->assertSame(['fired' => 0, 'skipped' => 0, 'missed' => 0], $this->fire());
        $this->assertTrue($moved);
        $this->assertSame(0, FlowSession::query()->count());
        $this->assertSame('2026-11-21 09:00:00', $this->trigger()->next_run_at?->format('Y-m-d H:i:s'));
        $this->assertNull($this->trigger()->last_run_at);
        Queue::assertNothingPushed();
    }

    public function test_a_schedule_run_records_flow_started_once_at_its_activation(): void
    {
        $events = [];
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
        $started = static function () use (&$events): int {
            return count(array_filter(
                $events,
                static fn (AnalyticsEvent $event): bool => AnalyticsEventType::FlowStarted === $event->eventType,
            ));
        };

        $this->liveSchedule('0 9 * * *');
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->fire();

        $this->assertSame(0, $started(), 'Creating the run pending records nothing.');

        $job = Queue::pushed(StartAutomationRunJob::class)->sole();
        $this->app->call([$job, 'handle']);

        $this->assertSame(1, $started());

        $this->app->call([$job, 'handle']);

        $this->assertSame(1, $started(), 'A retry of the start job records nothing more.');
    }

    public function test_deleting_the_flow_cancels_its_run_waiting_to_start_and_the_start_job_starts_nothing(): void
    {
        [$draft] = $this->liveSchedule('* * * * *');
        Carbon::setTestNow('2026-11-20 08:31:00');
        $this->fire();
        $this->assertSame(FlowSessionStatus::Pending, FlowSession::query()->sole()->status);

        $this->app->make(FlowDraftService::class)->delete($draft);

        $run = FlowSession::query()->sole();
        $this->assertSame(FlowSessionStatus::Cancelled, $run->status);
        $this->assertSame(2, $run->version);
        $this->assertNull($this->trigger()->next_run_at);

        $this->app->call([Queue::pushed(StartAutomationRunJob::class)->sole(), 'handle']);

        $this->assertSame(FlowSessionStatus::Cancelled, FlowSession::query()->sole()->status, 'The start job finds nothing to start.');
    }

    public function test_a_run_that_has_started_still_keeps_the_flow_from_being_deleted(): void
    {
        [$draft] = $this->liveSchedule('* * * * *');
        Carbon::setTestNow('2026-11-20 08:31:00');
        $this->fire();
        $pending = FlowSession::query()->sole();
        $pending->replicate()->forceFill(['status' => FlowSessionStatus::WaitingInput, 'fire_key' => 'schedule:2026-11-20T08:00Z'])->save();

        try {
            $this->app->make(FlowDraftService::class)->delete($draft);
            $this->fail('A started run must refuse the delete.');
        } catch (FlowHasLiveSessionsException) {
        }

        $this->assertSame(FlowSessionStatus::Pending, $pending->refresh()->status, 'The refusal rolls the cancel back.');
        $this->assertNotNull(FlowDraft::query()->find($draft->getKey()));
        $this->assertNotNull($this->trigger()->next_run_at);
    }

    public function test_a_slot_claim_reaching_the_flow_while_it_is_deleted_leaves_no_run_waiting_to_start(): void
    {
        [$draft] = $this->liveSchedule('* * * * *');
        Carbon::setTestNow('2026-11-20 08:31:00');

        // The firer claims the slot right after the delete checked the flow's live sessions, before its last writes.
        $this->whenFlowSessionsAreRead('exists', fn (): array => $this->fire());

        $this->app->make(FlowDraftService::class)->delete($draft);

        $this->assertSame(
            0,
            FlowSession::query()->where('status', FlowSessionStatus::Pending->value)->count(),
            'A run claimed while the flow is deleted would be started by its job.',
        );
        $this->assertNull($this->trigger()->next_run_at);
    }

    public function test_a_start_that_activates_the_run_while_the_flow_is_deleted_refuses_the_delete(): void
    {
        [$draft] = $this->liveSchedule('* * * * *');
        Carbon::setTestNow('2026-11-20 08:31:00');
        $this->fire();
        $run = FlowSession::query()->sole();

        // The start job activates the run between the delete's read and its cancel.
        $raced = false;
        $this->whenFlowSessionsAreRead('parent_session_id', static function () use ($run, &$raced): int {
            $raced = true;

            return FlowSession::query()->whereKey($run->getKey())->update([
                'status'  => FlowSessionStatus::Active->value,
                'version' => $run->version + 1,
            ]);
        });

        // The delete's own check for live sessions (`select exists ...`): it must not be what refuses the delete.
        $lateChecks = 0;
        DB::listen(static function (QueryExecuted $query) use (&$raced, &$lateChecks): void {
            if ($raced && str_starts_with($query->sql, 'select exists') && str_contains($query->sql, 'flow_sessions')) {
                ++$lateChecks;
            }
        });

        try {
            $this->app->make(FlowDraftService::class)->delete($draft);
            $this->fail('A run whose start won must refuse the delete.');
        } catch (FlowHasLiveSessionsException) {
        }

        $this->assertTrue($raced, 'The start must have won the race inside the delete.');
        $this->assertSame(0, $lateChecks, 'The refusal comes from the lost optimistic-lock write of the cancel, before the live-sessions check.');

        $this->assertNotNull(FlowDraft::query()->find($draft->getKey()));
        $this->assertNotNull($this->trigger()->next_run_at, 'The refusal rolls the stop of the schedule back.');
    }

    public function test_a_start_that_loses_to_the_cancel_of_its_run_starts_nothing_and_does_not_fail(): void
    {
        $this->liveSchedule('* * * * *');
        Carbon::setTestNow('2026-11-20 08:31:00');
        $this->fire();
        $run = FlowSession::query()->sole();

        // The flow's deletion cancels the run right after the start read it as pending.
        $cancelled = false;
        DB::listen(static function (QueryExecuted $query) use ($run, &$cancelled): void {
            if ($cancelled || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'flow_sessions')) {
                return;
            }

            $cancelled = true;
            FlowSession::query()->whereKey($run->getKey())->update([
                'status'  => FlowSessionStatus::Cancelled->value,
                'version' => $run->version + 1,
            ]);
        });

        $this->assertFalse($this->app->make(FlowEngineInterface::class)->activate($run));
        $this->assertSame(FlowSessionStatus::Cancelled, $run->refresh()->status);
    }

    public function test_a_flow_switched_off_in_the_flows_list_spends_its_slot_and_is_not_caught_up(): void
    {
        Log::spy();
        [$draft, $triggerId] = $this->liveSchedule('0 9 * * *');
        $draft->forceFill(['is_active' => false])->save();
        Carbon::setTestNow('2026-11-20 09:00:00');

        $this->assertSame(['fired' => 0, 'skipped' => 1, 'missed' => 0], $this->fire());
        $this->assertSame(0, FlowSession::query()->count());
        $this->assertSame(0, FlowTriggerState::query()->count(), 'Run admission is not asked: nothing is counted on the trigger.');
        $this->assertSame('2026-11-21 09:00:00', $this->trigger()->next_run_at?->format('Y-m-d H:i:s'), 'The slot is spent.');
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('info')->with('flow.schedule.tick_skipped', Mockery::on(
            fn (array $context): bool => $triggerId === $context['trigger_id']
                && ScheduleTriggerFirer::SKIP_FLOW_OFF === $context['reason']
                && '2026-11-20T09:00:00+00:00' === $context['slot'],
        ))->once();

        // Turned back on before the next slot: the spent one is not caught up.
        $draft->forceFill(['is_active' => true])->save();
        Carbon::setTestNow('2026-11-20 09:01:00');

        $this->assertSame(['fired' => 0, 'skipped' => 0, 'missed' => 0], $this->fire());
        $this->assertSame(0, FlowSession::query()->count());

        Carbon::setTestNow('2026-11-21 09:00:00');

        $this->assertSame(['fired' => 1, 'skipped' => 0, 'missed' => 0], $this->fire());
    }

    public function test_a_switched_off_or_not_yet_due_trigger_is_not_claimed(): void
    {
        $this->liveSchedule('0 9 * * *');
        Carbon::setTestNow('2026-11-20 08:59:00');

        $this->assertSame(['fired' => 0, 'skipped' => 0, 'missed' => 0], $this->fire());

        FlowTrigger::query()->toBase()->update(['is_active' => false]);
        Carbon::setTestNow('2026-11-20 09:00:00');

        $this->assertSame(['fired' => 0, 'skipped' => 0, 'missed' => 0], $this->fire());
        $this->assertSame(0, FlowSession::query()->count());
    }

    public function test_a_row_with_a_cron_that_cannot_run_stops_coming_due(): void
    {
        Log::spy();
        $this->liveSchedule('0 9 * * *');
        // An older validator let it through; the row is written past today's.
        FlowTrigger::query()->toBase()->update(['config' => json_encode(['cron' => '* * * * * *', 'timezone' => 'UTC'])]);
        Carbon::setTestNow('2026-11-20 09:00:00');

        $this->assertSame(['fired' => 0, 'skipped' => 0, 'missed' => 0], $this->fire());
        $this->assertNull($this->trigger()->next_run_at);
        $this->assertSame(0, FlowSession::query()->count());
        Log::shouldHaveReceived('warning')->with('flow.schedule.unusable_config', Mockery::any())->once();
    }

    /**
     * @return array{fired: int, skipped: int, missed: int}
     */
    private function fire(): array
    {
        return $this->app->make(ScheduleTriggerFirer::class)->fireDue($this->tenantId, CarbonImmutable::now());
    }

    /**
     * Runs {@code $race} once, right after the first read of `flow_sessions` whose SQL holds {@code $marker}: the
     * deletion's read of the runs waiting to start (`parent_session_id`) or its check for live sessions (`exists`).
     */
    private function whenFlowSessionsAreRead(string $marker, callable $race): void
    {
        $raced = false;
        DB::listen(static function (QueryExecuted $query) use ($marker, $race, &$raced): void {
            if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'flow_sessions') || ! str_contains($query->sql, $marker)) {
                return;
            }

            $raced = true;
            $race();
        });
    }

    private function trigger(): FlowTrigger
    {
        return FlowTrigger::query()->sole();
    }

    /**
     * A published flow whose one trigger is a schedule.
     *
     * @return array{FlowDraft, string}
     */
    private function liveSchedule(string $cron, string $timezone = 'UTC'): array
    {
        $triggerId = mb_strtolower(Str::ulid()->toRfc4122());
        $draft     = FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => [],
            'triggers'     => [[
                'id'        => $triggerId,
                'type'      => 'schedule',
                'is_active' => true,
                'priority'  => 100,
                'config'    => ['cron' => $cron, 'timezone' => $timezone],
            ]],
        ]);

        $this->app->make(PublishFlowService::class)->execute($draft->flow_id);

        return [$draft, $triggerId];
    }
}
