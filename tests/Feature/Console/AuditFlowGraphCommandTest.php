<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\FeatureTestCase;

/**
 * The tenant infrastructure is mocked on purpose: a real {@see TenantSwitcher}
 * purges the default connection, which on the suite's in-memory SQLite would
 * throw the seeded data away mid-run.
 */
final class AuditFlowGraphCommandTest extends FeatureTestCase
{
    private string $tenantId;

    private string $assistantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId    = (string) Str::uuid();
        $this->assistantId = (string) Assistant::factory()->create(['tenant_id' => $this->tenantId])->getKey();

        $this->definition('Valid flow', [$this->node('a'), $this->node('b')], [$this->edge('a', 'b')]);
        $this->definition('Two entries', [$this->node('a'), $this->node('b'), $this->node('c')], [$this->edge('a', 'c')]);
        $this->definition('Retired flow', [$this->node('a'), $this->node('b')], [], isActive: false);

        FlowDraft::query()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistantId,
            'flow_id'      => (string) Str::uuid(),
            'name'         => 'Empty draft',
            'nodes'        => [],
            'edges'        => [],
        ]);
    }

    public function test_it_lists_only_the_broken_active_definition(): void
    {
        $this->bindCommand();

        $exitCode = Artisan::call('flow:audit-graph');
        $output   = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Two entries', $output);
        $this->assertStringContainsString('entry_node_count', $output);
        $this->assertStringContainsString('2 graph(s) audited across 1 tenant(s): 1 with problems, 1 finding(s).', $output);
        $this->assertStringNotContainsString('Valid flow', $output);
        $this->assertStringNotContainsString('Retired flow', $output);
    }

    public function test_json_output_has_summary_and_findings(): void
    {
        $this->bindCommand();

        Artisan::call('flow:audit-graph', ['--json' => true]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $payload['summary']['tenants_scanned']);
        $this->assertSame(1, $payload['summary']['graphs_with_issues']);
        $this->assertCount(1, $payload['findings']);

        $finding = $payload['findings'][0];

        $this->assertSame('alpha', $finding['tenant']);
        $this->assertSame('Two entries', $finding['flow_name']);
        $this->assertSame('definition', $finding['source']);
        $this->assertSame('entry_node_count', $finding['code']);
        $this->assertSame('nodes', $finding['path']);
        $this->assertSame(0, Artisan::call('flow:audit-graph', ['--json' => true]));
    }

    public function test_a_broken_draft_is_reported_as_a_draft(): void
    {
        FlowDraft::query()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistantId,
            'flow_id'      => (string) Str::uuid(),
            'name'         => 'Cyclic draft',
            'nodes'        => [$this->node('a'), $this->node('b')],
            'edges'        => [$this->edge('a', 'b'), $this->edge('b', 'a')],
        ]);

        $this->bindCommand();

        Artisan::call('flow:audit-graph', ['--json' => true]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $sources = array_column($payload['findings'], 'source');
        sort($sources);

        $this->assertSame(['definition', 'draft'], $sources);
    }

    public function test_definitions_are_audited_as_stored_but_drafts_are_stripped(): void
    {
        $note = ['id' => 'note', 'type' => 'comment', 'version' => 1, 'config' => []];

        FlowDraft::query()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistantId,
            'flow_id'      => (string) Str::uuid(),
            'name'         => 'Draft with note',
            'nodes'        => [$this->node('a'), $this->node('b'), $note],
            'edges'        => [$this->edge('a', 'b')],
        ]);
        $this->definition('Definition with note', [$this->node('a'), $this->node('b'), $note], [$this->edge('a', 'b')]);

        $this->bindCommand();

        Artisan::call('flow:audit-graph', ['--json' => true]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $names = array_column($payload['findings'], 'flow_name');
        sort($names);

        $this->assertSame(['Definition with note', 'Two entries'], $names);
    }

    public function test_an_unknown_tenant_fails(): void
    {
        $this->bindCommand(findBySlug: null);

        $exitCode = Artisan::call('flow:audit-graph', ['--tenant' => 'nope']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown tenant', Artisan::output());
    }

    public function test_tenant_option_audits_only_that_tenant(): void
    {
        $this->bindCommand(findBySlug: true);

        $exitCode = Artisan::call('flow:audit-graph', ['--tenant' => 'alpha', '--json' => true]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['summary']['tenants_scanned']);
    }

    public function test_a_failing_tenant_is_listed_and_fails_the_command(): void
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('getSlug')->andReturn('broken');
        $tenant->shouldReceive('getId')->andReturn('broken');

        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findAllActive')->andReturn([$tenant]);

        $tenantDatabase = Mockery::mock(TenantDatabaseManagerInterface::class);
        $tenantDatabase->shouldReceive('switchTo')->andThrow(new RuntimeException('schema missing'));
        $tenantDatabase->shouldReceive('restore');

        $permissions = Mockery::mock(PermissionRegistrar::class);
        $permissions->shouldReceive('clearPermissionsCollection');

        $this->app->instance(TenantRepositoryInterface::class, $repository);
        $this->app->instance(
            TenantSwitcher::class,
            new TenantSwitcher(new TenantContext(), $tenantDatabase, $permissions),
        );

        $exitCode = Artisan::call('flow:audit-graph', ['--json' => true]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame(['broken' => 'schema missing'], $payload['failed']);
        $this->assertSame(0, $payload['summary']['tenants_scanned']);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     */
    private function definition(string $name, array $nodes, array $edges, bool $isActive = true): void
    {
        FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => $name,
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => $isActive,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function node(string $id): array
    {
        return ['id' => $id, 'type' => 'send_message', 'version' => 1, 'config' => []];
    }

    /**
     * @return array<string, mixed>
     */
    private function edge(string $from, string $to): array
    {
        return ['id' => "{$from}-{$to}", 'from' => $from, 'to' => $to, 'handle' => 'default'];
    }

    private function bindCommand(?bool $findBySlug = false): void
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('getSlug')->andReturn('alpha');
        $tenant->shouldReceive('getId')->andReturn('alpha');

        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findAllActive')->andReturn([$tenant]);
        $repository->shouldReceive('findBySlug')->andReturn(true === $findBySlug ? $tenant : null);

        $tenantDatabase = Mockery::mock(TenantDatabaseManagerInterface::class);
        $tenantDatabase->shouldReceive('switchTo');
        $tenantDatabase->shouldReceive('restore');

        $permissions = Mockery::mock(PermissionRegistrar::class);
        $permissions->shouldReceive('clearPermissionsCollection');

        $this->app->instance(TenantRepositoryInterface::class, $repository);
        $this->app->instance(
            TenantSwitcher::class,
            new TenantSwitcher(new TenantContext(), $tenantDatabase, $permissions),
        );
    }
}
