<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Retention\FlowVersionRetention;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\PlatformSettings;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * `flow:prune-versions` reads the retention setting and prunes every active tenant through
 * {@see \App\Domains\Flow\Retention\FlowVersionPruner}; which versions go is proven in `FlowVersionPrunerTest`.
 */
final class PruneFlowVersionsCommandTest extends FeatureTestCase
{
    private string $tenantId;

    private string $flowId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->app->make(TenantRepositoryInterface::class)->findAllActive()[0]->getId();
        $this->flowId   = (string) Str::uuid();

        for ($version = 1; $version <= 5; ++$version) {
            FlowDefinition::query()->create([
                'tenant_id' => $this->tenantId,
                'flow_id'   => $this->flowId,
                'version'   => $version,
                'name'      => 'Versions',
                'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
                'edges'     => [],
                'is_active' => 5 === $version,
            ]);
        }
    }

    public function test_env_values_outside_the_range_fall_back_to_the_default(): void
    {
        $this->assertSame(0, FlowVersionRetention::parseKeep(null, 0));
        $this->assertSame(50, FlowVersionRetention::parseKeep(' 50 ', 0));
        $this->assertSame(0, FlowVersionRetention::parseKeep('-1', 0));
        $this->assertSame(0, FlowVersionRetention::parseKeep('99999', 0));
        $this->assertSame(0, FlowVersionRetention::parseKeep('50 versions', 0));
    }

    public function test_by_default_every_version_is_kept(): void
    {
        $this->artisan('flow:prune-versions')->expectsOutputToContain('retention is disabled')->assertSuccessful();

        $this->assertSame(5, FlowDefinition::query()->count());
    }

    public function test_a_stored_setting_prunes_and_a_dry_run_only_counts(): void
    {
        $this->store(2);

        $slug = $this->app->make(TenantRepositoryInterface::class)->findAllActive()[0]->getSlug();

        $this->artisan('flow:prune-versions', ['--dry-run' => true])
            ->expectsOutput("✔ {$slug}: would delete 3")
            ->expectsOutput('Versions to delete: 3')
            ->assertSuccessful();
        $this->assertSame(5, FlowDefinition::query()->count());

        $this->artisan('flow:prune-versions')
            ->expectsOutput("✔ {$slug}: deleted 3")
            ->expectsOutput('Deleted versions: 3')
            ->assertSuccessful();
        $this->assertSame([4, 5], FlowDefinition::query()->orderBy('version')->pluck('version')->map(static fn ($v): int => (int) $v)->all());
    }

    public function test_a_failing_tenant_is_reported_and_the_others_are_still_pruned(): void
    {
        $this->store(1);

        $real   = $this->app->make(TenantRepositoryInterface::class)->findAllActive()[0];
        $broken = new RuntimeTenant(id: (string) Str::uuid(), schemaName: 'broken', slug: 'broken');

        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->allows('findAllActive')->andReturn([$broken, $real]);
        $this->app->instance(TenantRepositoryInterface::class, $repository);

        $entries = 0;
        $this->app->make(TenantSwitcher::class)->registerContextHook(function () use (&$entries): Closure {
            if (1 === ++$entries) {
                throw new RuntimeException('schema unavailable');
            }

            return static function (): void {
            };
        });

        $this->artisan('flow:prune-versions')
            ->expectsOutput('✘ broken: schema unavailable')
            ->expectsOutput('Deleted versions: 4')
            ->assertFailed();
    }

    public function test_it_is_scheduled_daily_at_five_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'flow:prune-versions'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('0 5 * * *', $event->expression);
    }

    private function store(int $keepLast): void
    {
        $this->app->make(PlatformSettings::class)->update([FlowVersionRetention::SETTING => $keepLast], 'cli:test');
        $this->app->forgetScopedInstances();
    }
}
