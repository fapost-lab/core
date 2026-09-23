<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Statistics;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\NodeUsageStatisticsInterface;
use App\Domains\Flow\Logging\FlowLogEntry;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Logging\FlowLogWriter;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Statistics\NodeTypeUsage;
use App\Domains\Flow\Statistics\NodeUsageReport;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

final class NodeUsageStatisticsServiceTest extends FeatureTestCase
{
    private string $tenantId;

    private FlowSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(NodeHandlerRegistry::class);
        $registry->register(UsageAlphaTestHandler::class);
        $registry->register(UsageUnusedTestHandler::class);

        $this->tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
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

    private function report(): NodeUsageReport
    {
        return $this->app->make(NodeUsageStatisticsInterface::class)
            ->reportForCurrentTenant(CarbonImmutable::now()->subDays(30));
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
    private function definition(string $name, bool $isActive, array $nodes): FlowDefinition
    {
        return FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
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
    private function writeLog(string $nodeType, int $nodeVersion, FlowLogStatus $status, CarbonImmutable $at): void
    {
        CarbonImmutable::setTestNow($at);

        try {
            $this->app->make(FlowLogWriter::class)->write(new FlowLogEntry(
                sessionId: (string) $this->session->getKey(),
                nodeId: 'n1',
                nodeType: $nodeType,
                nodeVersion: $nodeVersion,
                status: $status,
                sourceHandle: null,
                stateChanges: null,
                resolved: null,
                error: null,
            ));
        } finally {
            CarbonImmutable::setTestNow();
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
        return 'Test';
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
        return 'Test';
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
