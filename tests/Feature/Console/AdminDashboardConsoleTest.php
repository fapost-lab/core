<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Http\Controllers\Admin\DashboardController;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * The admin dashboard on Inertia: the figures of Filament's `StatsOverview` and the days of `FlowActivityChart`,
 * bounded by the tenant, and each figure only for a user who may list that kind of record.
 */
final class AdminDashboardConsoleTest extends InertiaConsoleTestCase
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

    public function test_a_user_without_rights_gets_the_page_without_figures(): void
    {
        $this->actingAs(User::factory()->create())
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats', ['assistants' => null, 'contacts' => null, 'channels' => null, 'flows' => null, 'sessions' => null, 'staff' => null])
                ->where('activity', null)
                ->etc());
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

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get($this->panelUrl('/admin'))->assertRedirect(route('filament.admin.auth.login'));
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
        $flow     = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
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

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
