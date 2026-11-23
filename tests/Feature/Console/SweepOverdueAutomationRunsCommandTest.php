<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Flow\Enums\FlowRunFailureReason;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Closure;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * `flow:sweep-overdue-automation-runs` sweeps every active tenant that is not stopped through
 * {@see \App\Domains\Flow\Orchestration\AutomationRunDeadlineSweeper}; which runs fail is proven in
 * `AutomationRunDeadlineSweeperTest`.
 */
final class SweepOverdueAutomationRunsCommandTest extends FeatureTestCase
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
            'name'      => 'Deadline',
            'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    public function test_an_overdue_run_of_an_active_tenant_is_failed(): void
    {
        $run = $this->overdueRun();

        $this->artisan('flow:sweep-overdue-automation-runs')->assertExitCode(0);

        $this->assertSame(FlowSessionStatus::Failed, $run->refresh()->status);
        $this->assertSame(FlowRunFailureReason::DeadlineExceeded, $run->failure_reason);
    }

    public function test_a_stopped_tenant_is_skipped(): void
    {
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $run = $this->overdueRun();

        $this->artisan('flow:sweep-overdue-automation-runs')->assertExitCode(0);

        $this->assertSame(FlowSessionStatus::Active, $run->refresh()->status);
    }

    public function test_a_failing_tenant_is_reported_and_the_others_are_still_swept(): void
    {
        $tenants = $this->app->make(TenantRepositoryInterface::class);
        $real    = $tenants->findAllActive()[0];
        $broken  = new RuntimeTenant(id: (string) Str::uuid(), schemaName: 'broken', slug: 'broken');
        $run     = $this->overdueRun();

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

        $this->artisan('flow:sweep-overdue-automation-runs')
            ->expectsOutput('✘ broken: schema unavailable')
            ->assertExitCode(0);

        $this->assertSame(FlowSessionStatus::Failed, $run->refresh()->status);
    }

    public function test_it_is_scheduled_every_minute_without_overlapping_and_a_killed_pass_frees_it_in_minutes(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'flow:sweep-overdue-automation-runs'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt, 'A scheduler killed mid-pass must not silence the sweeper for a day.');
        $this->assertSame('* * * * *', $event->expression);
    }

    private function overdueRun(): FlowSession
    {
        return FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'flow_definition_id' => $this->definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'end',
            'status'             => FlowSessionStatus::Active,
            'run_kind'           => FlowRunKind::Automation,
            'trigger_id'         => (string) Str::uuid(),
            'deadline_at'        => now()->subMinute(),
            'state'              => [],
            'version'            => 1,
        ]);
    }
}
