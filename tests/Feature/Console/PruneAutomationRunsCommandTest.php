<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Retention\AutomationRunRetention;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\PlatformSettings;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * `flow:prune-automation-runs` reads both retention settings and prunes every active tenant through
 * {@see \App\Domains\Flow\Retention\AutomationRunPruner}; which sessions go is proven in `AutomationRunPrunerTest`.
 */
final class PruneAutomationRunsCommandTest extends FeatureTestCase
{
    private string $tenantId;

    private FlowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId   = $this->app->make(TenantRepositoryInterface::class)->findAllActive()[0]->getId();
        $this->definition = FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Retention',
            'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    public function test_env_values_outside_the_range_fall_back_to_the_default(): void
    {
        $this->assertSame(7, AutomationRunRetention::parseDays(null, 7));
        $this->assertSame(0, AutomationRunRetention::parseDays('0', 7));
        $this->assertSame(14, AutomationRunRetention::parseDays(' 14 ', 7));
        $this->assertSame(30, AutomationRunRetention::parseDays('-1', 30));
        $this->assertSame(30, AutomationRunRetention::parseDays('99999', 30));
    }

    public function test_the_defaults_keep_succeeded_runs_a_week_and_the_rest_a_month(): void
    {
        $succeededOld = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, 8);
        $failedYoung  = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, 8);
        $failedOld    = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, 31);
        $conversation = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, 400);

        $this->artisan('flow:prune-automation-runs')->expectsOutput('Deleted sessions: 2')->assertSuccessful();

        $this->assertFalse($this->exists($succeededOld));
        $this->assertTrue($this->exists($failedYoung));
        $this->assertFalse($this->exists($failedOld));
        $this->assertTrue($this->exists($conversation));
    }

    public function test_a_dry_run_counts_and_deletes_nothing(): void
    {
        $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Expired, 60);

        $this->artisan('flow:prune-automation-runs', ['--dry-run' => true])
            ->expectsOutput('Sessions to delete: 1')
            ->assertSuccessful();

        $this->assertSame(1, FlowSession::query()->count());
    }

    public function test_stored_settings_win_and_zero_keeps_that_class(): void
    {
        $this->store(0, 60);
        $succeeded = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, 400);
        $failed    = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, 45);

        $this->artisan('flow:prune-automation-runs')->expectsOutput('Deleted sessions: 0')->assertSuccessful();

        $this->assertTrue($this->exists($succeeded));
        $this->assertTrue($this->exists($failed));
    }

    public function test_both_zero_disables_the_prune(): void
    {
        $this->store(0, 0);
        $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, 400);

        $this->artisan('flow:prune-automation-runs')->expectsOutputToContain('retention is disabled')->assertSuccessful();

        $this->assertSame(1, FlowSession::query()->count());
    }

    public function test_a_failing_tenant_is_reported_and_the_others_are_still_pruned(): void
    {
        $tenants = $this->app->make(TenantRepositoryInterface::class);
        $real    = $tenants->findAllActive()[0];
        $broken  = new RuntimeTenant(id: (string) Str::uuid(), schemaName: 'broken', slug: 'broken');
        $old     = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, 60);

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

        $this->artisan('flow:prune-automation-runs')
            ->expectsOutput('✘ broken: schema unavailable')
            ->expectsOutput('Deleted sessions: 1')
            ->assertFailed();

        $this->assertFalse($this->exists($old));
    }

    public function test_it_is_scheduled_daily_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'flow:prune-automation-runs'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('30 4 * * *', $event->expression);
    }

    private function store(int $succeededDays, int $failedDays): void
    {
        $this->app->make(PlatformSettings::class)->update([
            AutomationRunRetention::SUCCEEDED_SETTING => $succeededDays,
            AutomationRunRetention::FAILED_SETTING    => $failedDays,
        ], 'cli:test');
        $this->app->forgetScopedInstances();
    }

    private function flowRun(FlowRunKind $kind, FlowSessionStatus $status, int $daysAgo): FlowSession
    {
        $session = FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'contact_id'         => FlowRunKind::Conversation === $kind ? Contact::factory()->forTenant($this->tenantId)->create()->getKey() : null,
            'flow_definition_id' => $this->definition->getKey(),
            'flow_version'       => 1,
            'status'             => $status,
            'run_kind'           => $kind,
            'state'              => [],
        ]);

        FlowSession::query()->whereKey($session->getKey())->toBase()->update([
            'updated_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);

        return $session;
    }

    private function exists(FlowSession $session): bool
    {
        return FlowSession::query()->whereKey($session->getKey())->exists();
    }
}
