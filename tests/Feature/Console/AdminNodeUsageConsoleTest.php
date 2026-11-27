<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The node usage screen of the admin panel: what `flow:node-usage` reports for the tenant, bounded by the tenant, open
 * to whoever may list flows, with run figures only for whoever may view the flow log, and narrowed to the assigned
 * assistants for anyone but an administrator.
 */
final class AdminNodeUsageConsoleTest extends FeatureTestCase
{
    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_an_administrator_sees_the_tenants_node_types_actions_and_runs(): void
    {
        $assistant = Assistant::factory()->create();
        $flow      = $this->flow($assistant, [
            ['id' => 'n1', 'type' => 'send_message', 'version' => 1, 'config' => []],
            ['id' => 'c1', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'handler', 'target' => 'crm.sync']],
        ]);
        $session = $this->flowSession($flow, $assistant);
        $this->log($session, 'n1', 'send_message', 'executed', CarbonImmutable::now()->subDays(2));
        $this->log($session, 'n1', 'send_message', 'failed', CarbonImmutable::now()->subHour());
        $this->log($session, 'c1', 'call', 'executed', CarbonImmutable::now()->subHour());

        $elsewhere = Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]);
        $this->flow($elsewhere, [['id' => 'n1', 'type' => 'usage_foreign', 'version' => 1, 'config' => []]]);

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin/node-usage'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/AdminNodeUsage/Index')
                ->where('navigation.mode', 'admin')
                ->where('runs', true)
                ->where('period', '30d')
                ->where('param', 'period')
                ->where('nodes', fn ($nodes): bool => [] === array_filter(
                    collect($nodes)->all(),
                    static fn (array $row): bool => 'usage_foreign' === $row['type'],
                ))
                ->where('nodes', fn ($nodes): bool => 1 === collect($nodes)->where('key', 'send_message@1')->count())
                ->where('nodes', function ($nodes): bool {
                    $row = collect($nodes)->firstWhere('key', 'send_message@1');

                    return true === $row['registered']
                        && 1 === $row['activeFlows']
                        && 1 === $row['activeNodes']
                        && 1 === $row['versions']
                        && 2 === $row['executions']
                        && 1 === $row['failures']
                        && '2026-11-20T11:00:00+00:00' === $row['lastRunAt'];
                })
                ->where('actions', [[
                    'id'          => 'crm.sync',
                    'solution'    => null,
                    'registered'  => false,
                    'activeFlows' => 1,
                    'versions'    => 1,
                    'executions'  => 1,
                    'failures'    => 0,
                    'lastRunAt'   => '2026-11-20T11:00:00+00:00',
                ]])
                ->where('unused', fn ($unused): bool => collect($unused)->contains('type', 'delay')
                    && ! collect($unused)->contains('type', 'send_message'))
                ->etc());
    }

    public function test_the_period_bounds_the_runs_and_an_unknown_one_falls_back_to_thirty_days(): void
    {
        $assistant = Assistant::factory()->create();
        $flow      = $this->flow($assistant, [['id' => 'n1', 'type' => 'send_message', 'version' => 1, 'config' => []]]);
        $session   = $this->flowSession($flow, $assistant);
        $this->log($session, 'n1', 'send_message', 'executed', CarbonImmutable::now()->subDays(3));
        $this->log($session, 'n1', 'send_message', 'executed', CarbonImmutable::now()->subMinutes(10));

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get($this->panelUrl('/admin/node-usage?period=24h'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('period', '24h')->where('nodes.0.executions', 1)->etc());

        $this->actingAs($admin)
            ->get($this->panelUrl('/admin/node-usage?period=90d'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('period', '30d')->where('nodes.0.executions', 2)->etc());
    }

    public function test_a_non_administrator_sees_only_their_assistants_and_no_runs_without_the_flow_log(): void
    {
        $mine   = Assistant::factory()->create();
        $theirs = Assistant::factory()->create();
        $own    = $this->flow($mine, [['id' => 'n1', 'type' => 'send_message', 'version' => 1, 'config' => []]]);
        $this->flow($theirs, [['id' => 'n1', 'type' => 'delay', 'version' => 1, 'config' => []]]);
        $this->log($this->flowSession($own, $mine), 'n1', 'send_message', 'executed', CarbonImmutable::now()->subHour());

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value);
        $user->assistants()->attach($mine);

        $this->actingAs($user)
            ->get($this->panelUrl('/admin/node-usage'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('runs', false)
                ->where('unused', null)
                ->has('nodes', 1)
                ->where('nodes.0.key', 'send_message@1')
                ->where('nodes.0.activeFlows', 1)
                ->where('nodes.0.executions', null)
                ->where('nodes.0.failures', null)
                ->where('nodes.0.lastRunAt', null)
                ->etc());

        $user->givePermissionTo(Permission::ViewFlowSessions->value);

        $this->actingAs($user)
            ->get($this->panelUrl('/admin/node-usage'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('runs', true)
                ->has('nodes', 1)
                ->where('nodes.0.executions', 1)
                ->etc());
    }

    public function test_the_unused_types_cover_the_whole_retention_whatever_period_is_shown(): void
    {
        $assistant = Assistant::factory()->create();
        $flow      = $this->flow($assistant, [['id' => 'n1', 'type' => 'send_message', 'version' => 1, 'config' => []]]);
        // A delay that ran two hours ago on a version no session is on any more.
        $this->log($this->flowSession($flow, $assistant), 'n9', 'delay', 'executed', CarbonImmutable::now()->subHours(2));

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin/node-usage?period=1h'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('period', '1h')
                ->where('unused', fn ($unused): bool => ! collect($unused)->contains('type', 'delay')
                    && collect($unused)->contains('type', 'input'))
                ->etc());
    }

    public function test_without_the_flow_log_no_row_comes_from_the_flow_log_alone_and_the_period_is_ignored(): void
    {
        $assistant = Assistant::factory()->create();
        $flow      = $this->flow($assistant, [['id' => 'n1', 'type' => 'send_message', 'version' => 1, 'config' => []]]);
        $this->log($this->flowSession($flow, $assistant), 'n9', 'delay', 'executed', CarbonImmutable::now()->subHour());

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value);
        $user->assistants()->attach($assistant);

        $this->actingAs($user)
            ->get($this->panelUrl('/admin/node-usage?period=1h'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('period', '30d')
                ->has('nodes', 1)
                ->where('nodes.0.key', 'send_message@1')
                ->etc());
    }

    public function test_a_user_who_may_not_list_flows_is_refused_and_not_offered_the_screen(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ViewFlowSessions->value);

        $this->actingAs($user)->get($this->panelUrl('/admin/node-usage'))->assertForbidden();

        $this->actingAs($user)
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => ! str_contains((string) json_encode($groups, JSON_UNESCAPED_SLASHES), '/admin/node-usage'))
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/admin'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => str_contains((string) json_encode($groups, JSON_UNESCAPED_SLASHES), '/admin/node-usage'))
                ->etc());
    }

    /**
     * An active published flow of the assistant; it belongs to the assistant through its draft.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function flow(Assistant $assistant, array $nodes): FlowDefinition
    {
        $tenantId = (string) $assistant->tenant_id;
        $flowId   = (string) Str::uuid();
        FlowDraft::factory()->create(['tenant_id' => $tenantId, 'assistant_id' => $assistant->getKey(), 'flow_id' => $flowId]);

        return FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Flow',
            'nodes'     => $nodes,
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    private function flowSession(FlowDefinition $flow, Assistant $assistant): FlowSession
    {
        return FlowSession::query()->create([
            'tenant_id'          => (string) $assistant->tenant_id,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => Contact::factory()->forTenant((string) $assistant->tenant_id)->create()->getKey(),
            'flow_definition_id' => $flow->getKey(),
            'flow_version'       => $flow->version,
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 0,
        ]);
    }

    private function log(FlowSession $session, string $nodeId, string $nodeType, string $status, CarbonImmutable $at): void
    {
        // `flow_logs` is partitioned by month on PostgreSQL: an older row needs its partition.
        $this->app->make(FlowLogPartitionManagerInterface::class)->ensureMonthlyPartition($at);

        FlowLog::query()->create([
            'session_id'   => $session->getKey(),
            'node_id'      => $nodeId,
            'node_type'    => $nodeType,
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
