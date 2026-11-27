<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use App\Domains\Flow\Logging\DatabaseAnalyticsWriter;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowRunAnalytics;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Http\Controllers\Admin\DashboardController;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use DateTimeImmutable;
use Fapost\Foundation\Analytics\DTO\AnalyticsEvent;
use Fapost\Foundation\Analytics\Enums\AnalyticsEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The admin dashboard on Inertia: the figures of Filament's `StatsOverview`, the days of `FlowActivityChart` and the
 * runs per flow from `analytics_events`, bounded by the tenant, and each figure only for a user who may list that kind
 * of record.
 */
final class AdminDashboardConsoleTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_an_administrator_sees_the_tenants_figures_inside_the_admin_shell(): void
    {
        $active = Assistant::factory()->create(['is_active' => true]);
        Assistant::factory()->create(['is_active' => false]);
        Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]);
        $this->channel($active, true);
        $this->channel($active, false);
        $this->flowSession($active, FlowSessionStatus::WaitingInput);
        $this->flowSession($active, FlowSessionStatus::Paused);
        $this->flowSession($active, FlowSessionStatus::Completed);
        $this->flowSession(Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]), FlowSessionStatus::Paused);
        $admin = $this->admin();
        User::factory()->create();
        $this->app->make(PlatformSupportUserService::class)->ensure();

        $this->actingAs($admin)
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/AdminDashboard/Index')
                ->where('navigation.mode', 'admin')
                ->where('stats', [
                    'assistants' => ['total' => 2, 'active' => 1],
                    // One contact per session of this tenant.
                    'contacts' => ['total' => 3],
                    'channels' => ['total' => 2, 'active' => 1],
                    'flows'    => ['published' => 3],
                    'sessions' => ['waiting' => 2],
                    // The administrator and the other user; the platform support user is not one of the tenant's people.
                    'staff' => ['total' => 2],
                ])
                ->has('activity', 14)
                ->where('pollSeconds', DashboardController::POLL_SECONDS)
                ->etc());
    }

    public function test_a_figure_is_given_only_to_who_may_list_its_records(): void
    {
        $mine = Assistant::factory()->create(['is_active' => true]);
        Assistant::factory()->create(['is_active' => true]);
        $user = User::factory()->create();
        $user->givePermissionTo([Permission::ManageAssistants->value, Permission::ViewFlowSessions->value]);
        $user->assistants()->attach($mine);

        $this->actingAs($user)
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats', [
                    // Counted as the user's list shows them: only the assigned assistant.
                    'assistants' => ['total' => 1, 'active' => 1],
                    'contacts'   => null,
                    'channels'   => null,
                    'flows'      => null,
                    'sessions'   => ['waiting' => 0],
                    'staff'      => null,
                ])
                ->has('activity', 14)
                ->etc());
    }

    public function test_a_non_administrator_counts_only_the_assistants_they_may_list(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $mine   = Assistant::factory()->create();
        $theirs = Assistant::factory()->create();
        $this->channel($mine, true);
        $this->channel($theirs, true);
        $this->channel($theirs, false);
        $waiting = $this->flowSession($mine, FlowSessionStatus::WaitingInput);
        $hidden  = $this->flowSession($theirs, FlowSessionStatus::Paused);
        $this->flowSession($theirs, FlowSessionStatus::WaitingInput);
        $this->log($waiting, 'executed', $now->subHour());
        $this->log($hidden, 'failed', $now->subHour());

        $user = User::factory()->create();
        $user->givePermissionTo([
            Permission::ManageAssistants->value,
            Permission::ManageChannels->value,
            Permission::ManageFlowDefinitions->value,
            Permission::ViewFlowSessions->value,
        ]);
        $user->assistants()->attach($mine);

        $this->actingAs($user)
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.assistants', ['total' => 1, 'active' => 1])
                ->where('stats.channels', ['total' => 1, 'active' => 1])
                ->where('stats.flows', ['published' => 1])
                ->where('stats.sessions', ['waiting' => 1])
                ->where('activity.13', ['date' => '2026-10-10', 'executed' => 1, 'failed' => 0])
                ->etc());

        // An administrator sees the tenant.
        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.channels', ['total' => 3, 'active' => 2])
                ->where('stats.flows', ['published' => 3])
                ->where('stats.sessions', ['waiting' => 3])
                ->where('activity.13', ['date' => '2026-10-10', 'executed' => 1, 'failed' => 1])
                ->etc());
    }

    public function test_a_user_without_rights_gets_the_page_without_figures(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats', ['assistants' => null, 'contacts' => null, 'channels' => null, 'flows' => null, 'sessions' => null, 'staff' => null])
                ->where('activity', null)
                ->where('flowRuns', null)
                ->etc());
    }

    public function test_the_broadcasts_card_lists_the_latest_runs_of_the_tenant_newest_first(): void
    {
        $assistant = Assistant::factory()->create(['name' => 'Shop']);
        $now       = CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC');

        // Six runs, started an hour apart: the card shows the five most recent. A draft is not a run.
        $runs = [];

        for ($hour = 6; $hour >= 1; --$hour) {
            $runs[$hour] = $this->broadcast($assistant, "Run {$hour}", BroadcastStatus::Completed, $now->subHours($hour));
        }

        $this->broadcast($assistant, 'Draft', BroadcastStatus::Draft, null);
        $this->broadcast(Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]), 'Foreign', BroadcastStatus::Completed, $now);
        $runs[1]->forceFill(['total_recipients' => 10, 'sent_count' => 7, 'failed_count' => 2, 'skipped_count' => 1])->save();

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('broadcasts', 5)
                ->where('broadcasts.0', [
                    'id'            => (string) $runs[1]->getKey(),
                    'name'          => 'Run 1',
                    'assistantName' => 'Shop',
                    'status'        => 'completed',
                    'stopReason'    => null,
                    'sent'          => 7,
                    'total'         => 10,
                    'failed'        => 2,
                    'skipped'       => 1,
                    'startedAt'     => $now->subHour()->toIso8601String(),
                    'reportUrl'     => "/assistant/{$assistant->getKey()}/broadcasts/{$runs[1]->getKey()}",
                ])
                ->where('broadcasts.4.name', 'Run 5')
                ->etc());
    }

    public function test_the_broadcasts_card_is_for_who_may_manage_broadcasts_and_only_their_assistants(): void
    {
        $mine   = Assistant::factory()->create();
        $theirs = Assistant::factory()->create();
        $this->broadcast($mine, 'Mine', BroadcastStatus::Running, CarbonImmutable::now());
        $this->broadcast($theirs, 'Theirs', BroadcastStatus::Running, CarbonImmutable::now());

        $withoutPermission = User::factory()->create();
        $withoutPermission->givePermissionTo(Permission::ManageAssistants->value);
        $withoutPermission->assistants()->attach($mine);

        $this->actingAs($withoutPermission)
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('broadcasts', null)->etc());

        // The broadcast permission alone, on an assigned assistant: the card lists its runs, and their report opens.
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageBroadcast->value);
        $user->assistants()->attach($mine);
        $reportUrl = null;

        $this->actingAs($user)
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$reportUrl): void {
                $page->has('broadcasts', 1)->where('broadcasts.0.name', 'Mine')->etc();
                $reportUrl = $page->toArray()['props']['broadcasts'][0]['reportUrl'];
            });

        $this->assertIsString($reportUrl);
        $this->actingAs($user)->get($this->panelUrl($reportUrl))->assertOk();
    }

    public function test_the_activity_counts_completions_as_executed_and_only_the_tenants_last_fourteen_days(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $session = $this->flowSession(Assistant::factory()->create(), FlowSessionStatus::Active);
        $foreign = $this->flowSession(Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]), FlowSessionStatus::Active);

        $this->log($session, 'executed', $now->subHour());
        $this->log($session, 'terminal', $now->subHours(2));
        $this->log($session, 'failed', $now->subHours(3));
        // Never written by the engine today: counted in neither bucket.
        $this->log($session, 'conflict', $now->subHours(4));
        $this->log($session, 'executed', $now->subDays(13)->startOfDay());
        // Outside the window: the day before the first one.
        $this->log($session, 'failed', $now->subDays(14)->endOfDay());
        $this->log($foreign, 'failed', $now->subHour());

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('activity', 14)
                ->where('activity.0', ['date' => '2026-09-27', 'executed' => 1, 'failed' => 0])
                ->where('activity.12', ['date' => '2026-10-09', 'executed' => 0, 'failed' => 0])
                ->where('activity.13', ['date' => '2026-10-10', 'executed' => 2, 'failed' => 1])
                ->etc());
    }

    /**
     * `created_at` is a `timestamptz`, so a session TimeZone other than UTC (an installation may set one) shifts both the
     * rendering of a day and a window bound passed without a zone. Late in the UTC day a UTC+14 session would render the
     * row as tomorrow, or leave it after the window's end. Filament's chart kept the row on its own UTC day; so does this.
     */
    public function test_the_activity_days_do_not_shift_with_a_non_utc_session_timezone(): void
    {
        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('The session TimeZone this guards against exists on PostgreSQL only.');
        }

        $now = CarbonImmutable::parse('2026-10-10 20:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $session = $this->flowSession(Assistant::factory()->create(), FlowSessionStatus::Active);
        // Written before the TimeZone changes: the insert binds its timestamp without a zone too.
        $this->log($session, 'executed', $now->subMinute());
        $this->log($session, 'failed', $now->subDays(13)->startOfDay()->addMinute());

        $timeZone = (string) DB::selectOne('SHOW TimeZone')->TimeZone;
        DB::statement("SET TIME ZONE 'Pacific/Kiritimati'");

        try {
            $response = $this->actingAs($this->admin())->get($this->panelUrl('/admin'));
        } finally {
            DB::statement("SET TIME ZONE '{$timeZone}'");
        }

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activity.0', ['date' => '2026-09-27', 'executed' => 0, 'failed' => 1])
            ->where('activity.13', ['date' => '2026-10-10', 'executed' => 1, 'failed' => 0])
            ->etc());
    }

    public function test_the_runs_per_flow_count_starts_completions_and_failures_of_the_tenants_period(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $assistant = Assistant::factory()->create();
        $orders    = $this->flow($assistant, 'Orders');
        // A second version of the same flow counts as that flow.
        $ordersV2 = $this->version($orders, 2);
        $support  = $this->flow($assistant, 'Support');

        $first  = $this->flowRun($orders, $assistant);
        $second = $this->flowRun($ordersV2, $assistant);
        $third  = $this->flowRun($support, $assistant);

        $this->event($first, AnalyticsEventType::FlowStarted, $now->subHours(2));
        $this->event($first, AnalyticsEventType::FlowCompleted, $now->subHour());
        $this->event($second, AnalyticsEventType::FlowStarted, $now->subMinutes(30));
        $this->event($second, AnalyticsEventType::FlowFailed, $now->subMinutes(10));
        $this->event($third, AnalyticsEventType::FlowStarted, $now->subMinutes(5));
        // Not a figure of the card.
        $this->event($third, AnalyticsEventType::FlowCancelled, $now->subMinutes(4));
        $this->event($third, AnalyticsEventType::RagQueryMade, $now->subMinutes(3));
        // Before the default period (the last 24 hours).
        $this->event($third, AnalyticsEventType::FlowStarted, $now->subDays(2));
        // Another tenant's event, though it names a session of this one.
        $this->event($third, AnalyticsEventType::FlowStarted, $now->subMinutes(2), self::OTHER_TENANT_ID);
        // A run whose session is gone: counted apart, not dropped.
        $this->event((string) Str::ulid()->toRfc4122(), AnalyticsEventType::FlowStarted, $now->subMinutes(20));
        $this->event((string) Str::ulid()->toRfc4122(), AnalyticsEventType::FlowCompleted, $now->subMinutes(15));
        // Another writer of the contract may name a session in its own way: never cast, so the read does not fail.
        $this->event('not-a-session-id', AnalyticsEventType::FlowFailed, $now->subMinutes(12));

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flowRuns.period', '24h')
                ->where('flowRuns.periods', ['1h', '24h', '7d', '30d'])
                ->where('flowRuns.default', '24h')
                ->where('flowRuns.param', DashboardController::RUNS_PERIOD)
                ->where('flowRuns.total', 2)
                ->where('flowRuns.flows', [
                    ['flow_id' => $orders->flow_id, 'name' => 'Orders', 'started' => 2, 'completed' => 1, 'failed' => 1],
                    ['flow_id' => $support->flow_id, 'name' => 'Support', 'started' => 1, 'completed' => 0, 'failed' => 0],
                ])
                ->where('flowRuns.unattributed', ['started' => 1, 'completed' => 1, 'failed' => 1])
                ->etc());
    }

    public function test_the_runs_per_flow_take_the_chosen_period_and_fall_back_to_the_default(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $assistant = Assistant::factory()->create();
        $flow      = $this->flow($assistant, 'Orders');
        $run       = $this->flowRun($flow, $assistant);
        $this->event($run, AnalyticsEventType::FlowStarted, $now->subDays(3));
        $this->event($run, AnalyticsEventType::FlowStarted, $now->subDays(31));

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get($this->panelUrl('/admin?' . DashboardController::RUNS_PERIOD . '=7d'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flowRuns.period', '7d')
                ->where('flowRuns.flows.0.started', 1)
                ->etc());

        $this->actingAs($admin)
            ->get($this->panelUrl('/admin?' . DashboardController::RUNS_PERIOD . '=365d'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flowRuns.period', '24h')
                ->where('flowRuns.flows', [])
                ->where('flowRuns.total', 0)
                ->etc());
    }

    public function test_the_runs_per_flow_list_the_busiest_flows_and_name_a_deleted_flow_by_its_last_version(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $assistant = Assistant::factory()->create();

        for ($i = 0; $i <= FlowRunAnalytics::LIMIT; $i++) {
            $flow = $this->flow($assistant, sprintf('Flow %02d', $i));
            $run  = $this->flowRun($flow, $assistant);

            // Flow 00 is the quietest, and the one left out.
            for ($started = 0; $started <= $i; $started++) {
                $this->event($run, AnalyticsEventType::FlowStarted, $now->subMinutes(5));
            }
        }

        $deleted = $this->flow($assistant, 'Draft name');
        $this->version($deleted, 2, 'Last published name');
        FlowDraft::query()->where('flow_id', $deleted->flow_id)->delete();
        $this->event($this->flowRun($deleted, $assistant), AnalyticsEventType::FlowStarted, $now->subMinutes(5));

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flowRuns.total', FlowRunAnalytics::LIMIT + 2)
                ->has('flowRuns.flows', FlowRunAnalytics::LIMIT)
                ->where('flowRuns.flows.0.name', sprintf('Flow %02d', FlowRunAnalytics::LIMIT))
                ->where('flowRuns.flows.0.started', FlowRunAnalytics::LIMIT + 1)
                ->where('flowRuns.flows.' . (FlowRunAnalytics::LIMIT - 1) . '.name', 'Flow 01')
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flowRuns.flows', fn ($flows): bool => ! collect($flows)->contains('name', 'Flow 00'))
                ->etc());

        // With fewer flows, the deleted one shows its last published name.
        FlowSession::query()->whereNot('flow_definition_id', $deleted->getKey())->delete();

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flowRuns.flows.0.name', 'Last published name')
                ->etc());
    }

    public function test_a_non_administrator_sees_the_runs_of_their_assistants_and_no_unattributed_runs(): void
    {
        $now = CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $mine   = Assistant::factory()->create();
        $theirs = Assistant::factory()->create();
        $own    = $this->flow($mine, 'Mine');
        $other  = $this->flow($theirs, 'Theirs');
        $this->event($this->flowRun($own, $mine), AnalyticsEventType::FlowStarted, $now->subHour());
        $this->event($this->flowRun($other, $theirs), AnalyticsEventType::FlowStarted, $now->subHour());
        $this->event((string) Str::ulid()->toRfc4122(), AnalyticsEventType::FlowStarted, $now->subHour());

        $user = User::factory()->create();
        $user->givePermissionTo([Permission::ManageAssistants->value, Permission::ViewFlowSessions->value]);
        $user->assistants()->attach($mine);

        $this->actingAs($user)
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flowRuns.flows', [['flow_id' => $own->flow_id, 'name' => 'Mine', 'started' => 1, 'completed' => 0, 'failed' => 0]])
                ->where('flowRuns.total', 1)
                ->where('flowRuns.unattributed', null)
                ->etc());
    }

    /**
     * `occurred_at` is a `timestamptz`: a bound without its offset is read in the session's TimeZone, which would move the
     * window and drop a run of the last hour under a UTC+14 session.
     */
    public function test_the_runs_per_flow_window_does_not_shift_with_a_non_utc_session_timezone(): void
    {
        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('The session TimeZone this guards against exists on PostgreSQL only.');
        }

        $now = CarbonImmutable::parse('2026-10-10 20:00:00', 'UTC');
        CarbonImmutable::setTestNow($now);

        $assistant = Assistant::factory()->create();
        $flow      = $this->flow($assistant, 'Orders');
        $this->event($this->flowRun($flow, $assistant), AnalyticsEventType::FlowStarted, $now->subMinutes(30));

        $timeZone = (string) DB::selectOne('SHOW TimeZone')->TimeZone;
        DB::statement("SET TIME ZONE 'Pacific/Kiritimati'");

        try {
            $response = $this->actingAs($this->admin())->get($this->panelUrl('/admin?' . DashboardController::RUNS_PERIOD . '=1h'));
        } finally {
            DB::statement("SET TIME ZONE '{$timeZone}'");
        }

        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flowRuns.flows.0.started', 1)
            ->etc());
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->panelUrl('/admin'))->assertRedirect(route('console.auth.login'));
    }

    private function channel(Assistant $assistant, bool $active): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => $assistant->tenant_id,
            'is_active'    => $active,
        ]));
    }

    private function flowSession(Assistant $assistant, FlowSessionStatus $status): FlowSession
    {
        $tenantId = (string) $assistant->tenant_id;
        $contact  = Contact::factory()->forTenant($tenantId)->create();
        $flowId   = (string) Str::uuid();
        // A published version belongs to an assistant through its draft.
        FlowDraft::factory()->create(['tenant_id' => $tenantId, 'assistant_id' => $assistant->getKey(), 'flow_id' => $flowId]);
        $flow = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        return FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $flow->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => $status,
            'version'            => 0,
        ]);
    }

    private function log(FlowSession $session, string $status, CarbonImmutable $at): void
    {
        // `flow_logs` is partitioned by month on PostgreSQL: an older row needs its partition.
        $this->app->make(FlowLogPartitionManagerInterface::class)->ensureMonthlyPartition($at);

        FlowLog::query()->create([
            'session_id'   => $session->getKey(),
            'node_id'      => 'node-1',
            'node_type'    => 'send_message',
            'node_version' => 1,
            'status'       => $status,
            'created_at'   => $at,
        ]);
    }

    private function broadcast(Assistant $assistant, string $name, BroadcastStatus $status, ?CarbonImmutable $startedAt): Broadcast
    {
        return Broadcast::query()->create([
            'tenant_id'    => $assistant->tenant_id,
            'assistant_id' => $assistant->getKey(),
            'name'         => $name,
            'message'      => ['en' => 'Hello'],
            'target_type'  => 'all',
            'status'       => $status->value,
            'started_at'   => $startedAt,
        ]);
    }

    private function flow(Assistant $assistant, string $name): FlowDefinition
    {
        $tenantId = (string) $assistant->tenant_id;
        $flowId   = (string) Str::uuid();
        FlowDraft::factory()->create(['tenant_id' => $tenantId, 'assistant_id' => $assistant->getKey(), 'flow_id' => $flowId, 'name' => $name]);

        return FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => $name,
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    private function version(FlowDefinition $flow, int $version, ?string $name = null): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $flow->tenant_id,
            'flow_id'   => $flow->flow_id,
            'version'   => $version,
            'name'      => $name ?? $flow->name,
            'nodes'     => [],
            'edges'     => [],
            // One version of a flow is active at a time; an older one keeps running its sessions.
            'is_active' => false,
        ]);
    }

    /**
     * A finished run of the given published version; its id is what the engine's analytics events name.
     */
    private function flowRun(FlowDefinition $flow, Assistant $assistant): string
    {
        $session = FlowSession::query()->create([
            'tenant_id'          => (string) $assistant->tenant_id,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => Contact::factory()->forTenant((string) $assistant->tenant_id)->create()->getKey(),
            'flow_definition_id' => $flow->getKey(),
            'flow_version'       => $flow->version,
            'current_node_id'    => null,
            'state'              => [],
            'status'             => FlowSessionStatus::Completed,
            'version'            => 0,
        ]);

        return (string) $session->getKey();
    }

    private function event(string $sessionId, AnalyticsEventType $type, CarbonImmutable $at, string $tenantId = self::TENANT_ID): void
    {
        $this->app->make(DatabaseAnalyticsWriter::class)->record(new AnalyticsEvent(
            tenantId: $tenantId,
            eventType: $type,
            payload: ['session_id' => $sessionId],
            occurredAt: new DateTimeImmutable($at->toIso8601String()),
        ));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
