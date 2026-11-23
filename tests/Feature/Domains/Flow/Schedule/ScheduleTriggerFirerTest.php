<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Schedule;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Models\FlowTriggerState;
use App\Domains\Flow\Orchestration\AutomationRunner;
use App\Domains\Flow\Schedule\ScheduleTriggerFirer;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Jobs\Flow\StartAutomationRunJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
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

        FlowDefinition::query()->where('flow_id', $draft->flow_id)->update(['is_active' => true]);
        $draft->delete();
        Carbon::setTestNow('2026-11-21 09:00:00');

        $this->assertSame(['fired' => 0, 'skipped' => 1, 'missed' => 0], $this->fire());
        $this->assertSame(0, FlowSession::query()->count());
        Log::shouldHaveReceived('warning')->with('flow.schedule.tick_skipped', Mockery::on(
            static fn (array $context): bool => ScheduleTriggerFirer::SKIP_FLOW_NOT_LIVE === $context['reason'],
        ))->twice();
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
        Log::shouldHaveReceived('warning')->with('flow.schedule.tick_skipped', Mockery::on(
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
