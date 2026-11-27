<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Statistics;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Contracts\NodeUsageStatisticsInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Logging\FlowLogEntry;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Logging\FlowLogWriter;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Statistics\ActionUsage;
use App\Domains\Flow\Statistics\NodeTypeUsage;
use App\Domains\Flow\Statistics\NodeUsageReport;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Call\CallContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

final class NodeUsageStatisticsServiceTest extends FeatureTestCase
{
    private string $tenantId;

    private FlowSession $session;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(NodeHandlerRegistry::class);
        $registry->register(UsageAlphaTestHandler::class);
        $registry->register(UsageUnusedTestHandler::class);

        $tenant = Tenant::query()->firstOrFail();
        $this->app->make(TenantContextInterface::class)->set($tenant);
        $this->tenantId = (string) $tenant->getId();

        $assistant = $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $contact   = Contact::factory()->forTenant($this->tenantId)->create();

        // Three nodes of usage_alpha@1 spread over two active flows, one
        // usage_alpha@2 with no handler, and one node that omits `version`.
        $active = $this->definition('Alpha', true, [
            ['id' => 'n1', 'type' => 'usage_alpha', 'version' => 1, 'config' => []],
            ['id' => 'n2', 'type' => 'usage_alpha', 'version' => 1, 'config' => []],
            ['id' => 'n3', 'type' => 'usage_alpha', 'version' => 2, 'config' => []],
        ]);

        $this->definition('Beta', true, [
            ['id' => 'n1', 'type' => 'usage_alpha', 'version' => 1, 'config' => []],
            ['id' => 'n2', 'type' => 'usage_beta', 'config' => []],
        ]);

        $this->definition('Archived', false, [
            ['id' => 'n1', 'type' => 'usage_ghost', 'version' => 1, 'config' => []],
        ]);

        $this->session = FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $active->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'n1',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_static_slice_counts_nodes_and_flows_in_active_definitions_only(): void
    {
        $report = $this->report();

        $alpha = $this->usage($report, 'usage_alpha', 1);

        $this->assertNotNull($alpha);
        $this->assertSame(3, $alpha->activeNodes, 'three usage_alpha@1 nodes live in active definitions');
        $this->assertSame(2, $alpha->activeFlows, 'they are spread over two distinct flows');

        $this->assertNull(
            $this->usage($report, 'usage_ghost', 1),
            'a node that only exists in an inactive definition must not be reported as used',
        );
    }

    public function test_node_without_a_version_key_counts_as_version_one(): void
    {
        $beta = $this->usage($this->report(), 'usage_beta', 1);

        $this->assertNotNull($beta, 'a node omitting `version` must land on version 1, as the engine logs it');
        $this->assertSame(1, $beta->activeNodes);
    }

    public function test_runtime_slice_aggregates_executions_and_failures_inside_the_window(): void
    {
        $this->writeLog('usage_alpha', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subDays(1));
        $this->writeLog('usage_alpha', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subDays(3));
        $this->writeLog('usage_alpha', 1, FlowLogStatus::Failed, CarbonImmutable::now()->subDays(2));

        $alpha = $this->usage($this->report(), 'usage_alpha', 1);

        $this->assertNotNull($alpha);
        $this->assertSame(3, $alpha->executions);
        $this->assertSame(1, $alpha->failures);
        $this->assertNotNull($alpha->lastExecutedAt);
        $this->assertSame(
            CarbonImmutable::now()->subDays(1)->toDateString(),
            $alpha->lastExecutedAt->toDateString(),
            'last run is the most recent row, not the most recent failure',
        );
    }

    public function test_executions_outside_the_window_are_excluded(): void
    {
        $this->writeLog('usage_alpha', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subDays(40));

        $alpha = $this->usage($this->report(), 'usage_alpha', 1);

        $this->assertNotNull($alpha);
        $this->assertSame(0, $alpha->executions, 'a row older than the window must not be counted');
        $this->assertNull($alpha->lastExecutedAt);
        $this->assertSame(3, $alpha->activeNodes, 'the static slice is unaffected by the window');
    }

    public function test_a_type_still_executing_but_absent_from_active_definitions_is_reported(): void
    {
        $this->writeLog('usage_retired', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subDays(1));

        $retired = $this->usage($this->report(), 'usage_retired', 1);

        $this->assertNotNull($retired, 'live sessions can still run a type no active definition contains');
        $this->assertSame(0, $retired->activeNodes);
        $this->assertSame(0, $retired->activeFlows);
        $this->assertSame(1, $retired->executions);
        $this->assertFalse($retired->isUsedInActiveFlows());
    }

    public function test_a_version_without_a_registered_handler_is_orphaned(): void
    {
        $report = $this->report();

        $registered = $this->usage($report, 'usage_alpha', 1);
        $orphan     = $this->usage($report, 'usage_alpha', 2);

        $this->assertNotNull($registered);
        $this->assertNotNull($orphan);
        $this->assertTrue($registered->handlerRegistered, 'usage_alpha@1 has a handler');
        $this->assertFalse($orphan->handlerRegistered, 'usage_alpha@2 has none, though the type is registered');
        $this->assertTrue($orphan->isOrphaned());

        $orphanKeys = array_map(
            static fn (NodeTypeUsage $usage): string => $usage->key(),
            $report->orphaned(),
        );

        $this->assertContains('usage_alpha@2', $orphanKeys);
        $this->assertNotContains('usage_alpha@1', $orphanKeys);
    }

    public function test_unused_registered_handler_types_are_listed(): void
    {
        $report = $this->report();

        $this->assertContains(
            'usage_unused',
            $report->unusedHandlerTypes,
            'a registered type nothing references is safe to retire',
        );
        $this->assertNotContains('usage_alpha', $report->unusedHandlerTypes);
    }

    public function test_a_version_a_live_session_is_on_counts_as_a_published_version_but_not_as_an_active_flow(): void
    {
        $flowId = (string) Str::uuid();
        $old    = $this->definition('Pinned', false, [['id' => 'n1', 'type' => 'usage_unused', 'version' => 1, 'config' => []]], $flowId, 1);
        $this->definition('Pinned', true, [['id' => 'n1', 'type' => 'usage_alpha', 'version' => 1, 'config' => []]], $flowId, 2);
        $this->sessionOn($old, $this->assistant, FlowSessionStatus::WaitingInput);

        $report = $this->report();
        $pinned = $this->usage($report, 'usage_unused', 1);

        $this->assertNotNull($pinned, 'a waiting session still runs the version it started on');
        $this->assertSame(0, $pinned->activeFlows);
        $this->assertSame(0, $pinned->activeNodes);
        $this->assertSame(1, $pinned->publishedVersions);
        $this->assertNotContains('usage_unused', $report->unusedHandlerTypes, 'a type a live session still needs is not safe to retire');
        $this->assertSame(3, $this->usage($report, 'usage_alpha', 1)?->publishedVersions, 'Alpha, Beta and the new version of Pinned');
    }

    public function test_a_version_only_ended_sessions_are_on_is_not_counted(): void
    {
        $old = $this->definition('Finished', false, [['id' => 'n1', 'type' => 'usage_unused', 'version' => 1, 'config' => []]]);
        $this->sessionOn($old, $this->assistant, FlowSessionStatus::Completed);

        $report = $this->report();

        $this->assertNull($this->usage($report, 'usage_unused', 1));
        $this->assertContains('usage_unused', $report->unusedHandlerTypes);
    }

    public function test_another_tenants_flows_and_runs_are_not_counted(): void
    {
        $otherTenant = '00000000-0000-0000-0000-0000000000ff';
        $assistant   = Assistant::factory()->create(['tenant_id' => $otherTenant]);
        $definition  = FlowDefinition::query()->create([
            'tenant_id' => $otherTenant,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Elsewhere',
            'nodes'     => [['id' => 'n1', 'type' => 'usage_foreign', 'version' => 1, 'config' => []]],
            'edges'     => [],
            'is_active' => true,
        ]);
        $session = FlowSession::query()->create([
            'tenant_id'          => $otherTenant,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => Contact::factory()->forTenant($otherTenant)->create()->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'status'             => 'active',
            'state'              => [],
            'version'            => 1,
        ]);
        $this->writeLog('usage_foreign', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subHour(), $session);

        $this->assertNull($this->usage($this->report(), 'usage_foreign', 1));
    }

    public function test_an_assistant_scope_counts_only_the_flows_and_runs_of_those_assistants(): void
    {
        $theirs = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $mine   = $this->definition('Mine', true, [['id' => 'n1', 'type' => 'usage_mine', 'version' => 1, 'config' => []]]);
        $other  = $this->definition('Theirs', true, [['id' => 'n1', 'type' => 'usage_theirs', 'version' => 1, 'config' => []]]);
        $this->draftFor($mine, $this->assistant);
        $this->draftFor($other, $theirs);
        $this->writeLog('usage_mine', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subHour(), $this->sessionOn($mine, $this->assistant, FlowSessionStatus::Active));
        $this->writeLog('usage_theirs', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subHour(), $this->sessionOn($other, $theirs, FlowSessionStatus::Active));

        $report = $this->report(Assistant::query()->whereKey($this->assistant->getKey()));

        $this->assertSame(1, $this->usage($report, 'usage_mine', 1)?->activeFlows);
        $this->assertSame(1, $this->usage($report, 'usage_mine', 1)?->executions);
        $this->assertNull($this->usage($report, 'usage_theirs', 1));
        $this->assertNull($this->usage($report, 'usage_alpha', 1), 'a flow without a draft belongs to no assistant');
    }

    public function test_actions_are_counted_from_the_call_nodes_that_name_them(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
        $this->app->make(ActionHandlerRegistry::class)->register(UsageProbeAction::class);

        $live = $this->definition('Calls', true, [
            ['id' => 'c1', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'handler', 'target' => 'core.usage_probe']],
            ['id' => 'c2', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'handler', 'target' => ' gone.action ']],
            ['id' => 'c3', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'handler', 'target' => '{{ vars.action }}']],
            ['id' => 'c4', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'http', 'target' => 'https://example.com']],
        ]);
        $session = $this->sessionOn($live, $this->assistant, FlowSessionStatus::Active);
        $this->writeLog('call', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subHours(2), $session, 'c1');
        $this->writeLog('call', 1, FlowLogStatus::Failed, CarbonImmutable::now()->subHour(), $session, 'c1');
        $this->writeLog('call', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subHour(), $session, 'c4');
        $this->writeLog('call', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subDays(40), $session, 'c1');

        $actions = $this->report()->actions;

        $this->assertSame(['core.usage_probe', 'gone.action'], array_map(static fn (ActionUsage $action): string => $action->actionId, $actions));

        [$probe, $gone] = $actions;
        $this->assertTrue($probe->registered);
        $this->assertNull($probe->solutionId, 'a Core action has no Solution');
        $this->assertSame(1, $probe->activeFlows);
        $this->assertSame(1, $probe->publishedVersions);
        $this->assertSame(2, $probe->executions, 'the runs of its node inside the window, not those of the http call');
        $this->assertSame(1, $probe->failures);
        $this->assertSame(CarbonImmutable::now()->subHour()->toDateTimeString(), $probe->lastExecutedAt?->toDateTimeString());
        $this->assertFalse($gone->registered);
        $this->assertSame(0, $gone->executions);
    }

    public function test_the_runs_of_a_replaced_version_still_count_for_its_action(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
        $flowId = (string) Str::uuid();
        $old    = $this->definition('Republished', false, [
            ['id' => 'c1', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'handler', 'target' => 'crm.create']],
            ['id' => 'c2', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'handler', 'target' => 'crm.retired']],
        ], $flowId, 1);
        $new = $this->definition('Republished', true, [
            ['id' => 'c1', 'type' => 'call', 'version' => 1, 'config' => ['transport' => 'handler', 'target' => 'crm.create']],
        ], $flowId, 2);
        $ended = $this->sessionOn($old, $this->assistant, FlowSessionStatus::Completed);
        $this->writeLog('call', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subDays(2), $ended, 'c1');
        $this->writeLog('call', 1, FlowLogStatus::Failed, CarbonImmutable::now()->subDays(2), $ended, 'c2');
        $this->writeLog('call', 1, FlowLogStatus::Executed, CarbonImmutable::now()->subHour(), $this->sessionOn($new, $this->assistant, FlowSessionStatus::Active), 'c1');

        $actions = [];

        foreach ($this->report()->actions as $action) {
            $actions[$action->actionId] = $action;
        }

        $this->assertSame(['crm.create', 'crm.retired'], array_keys($actions));
        $this->assertSame(2, $actions['crm.create']->executions, 'the runs of v1 in the window count, though its sessions ended');
        $this->assertSame(1, $actions['crm.create']->publishedVersions);
        $this->assertSame(0, $actions['crm.retired']->publishedVersions, 'only the replaced version sent to it');
        $this->assertSame(0, $actions['crm.retired']->activeFlows);
        $this->assertSame(1, $actions['crm.retired']->failures);
    }

    public function test_a_published_template_is_not_counted_as_a_flow(): void
    {
        FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Template',
            'nodes'     => [['id' => 'n1', 'type' => 'usage_template_only', 'version' => 1, 'config' => []]],
            'edges'     => [],
            'is_active' => true,
            'kind'      => 'template',
        ]);

        $this->assertNull($this->usage($this->report(), 'usage_template_only', 1));
    }

    /**
     * @param  Builder<Assistant>|null  $assistants
     */
    private function report(?Builder $assistants = null): NodeUsageReport
    {
        return $this->app->make(NodeUsageStatisticsInterface::class)
            ->reportForCurrentTenant(CarbonImmutable::now()->subDays(30), $assistants);
    }

    private function sessionOn(FlowDefinition $definition, Assistant $assistant, FlowSessionStatus $status): FlowSession
    {
        return FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => Contact::factory()->forTenant($this->tenantId)->create()->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'state'              => [],
            'status'             => $status->value,
            'version'            => 1,
        ]);
    }

    private function draftFor(FlowDefinition $definition, Assistant $assistant): void
    {
        FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'flow_id'      => $definition->flow_id,
            'assistant_id' => $assistant->getKey(),
        ]);
    }

    private function usage(NodeUsageReport $report, string $nodeType, int $nodeVersion): ?NodeTypeUsage
    {
        foreach ($report->usages as $usage) {
            if ($usage->nodeType === $nodeType && $usage->nodeVersion === $nodeVersion) {
                return $usage;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function definition(string $name, bool $isActive, array $nodes, ?string $flowId = null, int $version = 1): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => $flowId ?? (string) Str::uuid(),
            'version'   => $version,
            'name'      => $name,
            'nodes'     => $nodes,
            'edges'     => [],
            'is_active' => $isActive,
        ]);
    }

    /**
     * Backdates through the real writer rather than a raw insert: on PostgreSQL
     * `flow_logs` is range-partitioned and only the months around today exist,
     * so a raw insert into an older month has no partition to land in. The
     * writer creates the partition for whatever `now()` says.
     */
    private function writeLog(string $nodeType, int $nodeVersion, FlowLogStatus $status, CarbonImmutable $at, ?FlowSession $session = null, string $nodeId = 'n1'): void
    {
        $previous = CarbonImmutable::getTestNow();
        CarbonImmutable::setTestNow($at);

        try {
            $this->app->make(FlowLogWriter::class)->write(new FlowLogEntry(
                sessionId: (string) ($session ?? $this->session)->getKey(),
                nodeId: $nodeId,
                nodeType: $nodeType,
                nodeVersion: $nodeVersion,
                status: $status,
                sourceHandle: null,
                stateChanges: null,
                resolved: null,
                error: null,
            ));
        } finally {
            CarbonImmutable::setTestNow($previous);
        }
    }
}

final class UsageAlphaTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'usage_alpha';
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
        return 'Usage Alpha';
    }

    public function category(): string
    {
        return 'Logic';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class UsageUnusedTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'usage_unused';
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
        return 'Usage Unused';
    }

    public function category(): string
    {
        return 'Logic';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class UsageProbeAction implements ActionHandlerInterface
{
    public function id(): string
    {
        return 'core.usage_probe';
    }

    public function version(): int
    {
        return 1;
    }

    public function handle(array $parameters, CallContext $context): mixed
    {
        return null;
    }
}
