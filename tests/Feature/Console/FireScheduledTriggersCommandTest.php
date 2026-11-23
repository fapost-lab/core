<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Tenancy\Contracts\FlowScheduleRegistryInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Jobs\Flow\StartAutomationRunJob;
use Closure;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * `flow:fire-scheduled-triggers` enters only the tenants the landlord registry names as due, claims their slots through
 * {@see \App\Domains\Flow\Schedule\ScheduleTriggerFirer} (which slots fire is proven in `ScheduleTriggerFirerTest`) and
 * rewrites their registry rows from their triggers; `flow:rebuild-schedule-registry` rewrites every tenant's.
 */
final class FireScheduledTriggersCommandTest extends FeatureTestCase
{
    private string $tenantId;

    private string $triggerId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-11-20 09:00:00');

        $this->tenantId  = $this->app->make(TenantRepositoryInterface::class)->findAllActive()[0]->getId();
        $this->triggerId = mb_strtolower(Str::ulid()->toRfc4122());
        $this->dueSchedule();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_tenant_the_registry_names_fires_and_its_registry_row_moves_to_the_next_slot(): void
    {
        $this->registry()->put($this->tenantId, $this->triggerId, now());

        $this->artisan('flow:fire-scheduled-triggers')->assertExitCode(0);

        $this->assertSame(FlowSessionStatus::Pending, FlowSession::query()->sole()->status);
        Queue::assertPushed(StartAutomationRunJob::class, 1);
        $this->assertSame([$this->triggerId => '2026-11-21 09:00:00'], $this->registryRows());
    }

    public function test_a_due_trigger_the_registry_does_not_name_is_not_visited(): void
    {
        $this->artisan('flow:fire-scheduled-triggers')->assertExitCode(0);

        $this->assertSame(0, FlowSession::query()->count());
        $this->assertSame('2026-11-20 09:00:00', FlowTrigger::query()->sole()->next_run_at?->format('Y-m-d H:i:s'));
    }

    public function test_a_stopped_tenant_claims_nothing_and_keeps_its_rows(): void
    {
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $this->registry()->put($this->tenantId, $this->triggerId, now());

        $this->artisan('flow:fire-scheduled-triggers')->assertExitCode(0);

        $this->assertSame(0, FlowSession::query()->count());
        $this->assertSame('2026-11-20 09:00:00', FlowTrigger::query()->sole()->next_run_at?->format('Y-m-d H:i:s'));
        $this->assertSame([$this->triggerId => '2026-11-20 09:00:00'], $this->registryRows());
    }

    public function test_the_pass_rewrites_a_stale_registry_from_the_truth(): void
    {
        $gone = mb_strtolower(Str::ulid()->toRfc4122());
        $this->registry()->put($this->tenantId, $gone, now()->subMinute());

        $this->artisan('flow:fire-scheduled-triggers')->assertExitCode(0);

        $this->assertSame([$this->triggerId => '2026-11-21 09:00:00'], $this->registryRows(), 'The left-over row is gone, the real one recorded after it fired.');
        $this->assertSame(1, FlowSession::query()->count());
    }

    public function test_the_rows_of_a_tenant_that_no_longer_exists_are_forgotten(): void
    {
        $ghost = (string) Str::uuid();
        $this->registry()->put($ghost, mb_strtolower(Str::ulid()->toRfc4122()), now()->subMinute());

        $this->artisan('flow:fire-scheduled-triggers')->assertExitCode(0);

        $this->assertSame(0, DB::connection('landlord')->table('flow_schedule_registry')->where('tenant_id', $ghost)->count());
    }

    public function test_a_failing_tenant_is_reported_and_the_others_still_fire(): void
    {
        $real   = $this->app->make(TenantRepositoryInterface::class)->findAllActive()[0];
        $broken = new RuntimeTenant(id: (string) Str::uuid(), schemaName: 'broken', slug: 'broken');

        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->allows('findById')->with($broken->getId())->andReturn($broken);
        $repository->allows('findById')->with($real->getId())->andReturn($real);
        $this->app->instance(TenantRepositoryInterface::class, $repository);

        $this->registry()->put($broken->getId(), mb_strtolower(Str::ulid()->toRfc4122()), now()->subMinutes(2));
        $this->registry()->put($this->tenantId, $this->triggerId, now());

        $entries = 0;
        $this->app->make(TenantSwitcher::class)->registerContextHook(function () use (&$entries): Closure {
            if (1 === ++$entries) {
                throw new RuntimeException('schema unavailable');
            }

            return static function (): void {
            };
        });

        $this->artisan('flow:fire-scheduled-triggers')
            ->expectsOutput('✘ broken: schema unavailable')
            ->assertExitCode(0);

        $this->assertSame(1, FlowSession::query()->count());
    }

    public function test_the_rebuild_restores_a_lost_registry_row(): void
    {
        $this->assertSame([], $this->registryRows());

        $this->artisan('flow:rebuild-schedule-registry')->assertExitCode(0);

        $this->assertSame([$this->triggerId => '2026-11-20 09:00:00'], $this->registryRows());
    }

    public function test_both_passes_are_scheduled_without_overlapping(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $fire    = $events->first(fn ($event): bool => str_contains((string) $event->command, 'flow:fire-scheduled-triggers'));
        $rebuild = $events->first(fn ($event): bool => str_contains((string) $event->command, 'flow:rebuild-schedule-registry'));

        $this->assertNotNull($fire);
        $this->assertTrue($fire->withoutOverlapping);
        $this->assertSame('* * * * *', $fire->expression);
        $this->assertNotNull($rebuild);
        $this->assertTrue($rebuild->withoutOverlapping);
        $this->assertSame('0 * * * *', $rebuild->expression);
    }

    private function registry(): FlowScheduleRegistryInterface
    {
        return $this->app->make(FlowScheduleRegistryInterface::class);
    }

    /**
     * @return array<string, string>
     */
    private function registryRows(): array
    {
        return DB::connection('landlord')->table('flow_schedule_registry')
            ->where('tenant_id', $this->tenantId)
            ->pluck('next_run_at', 'trigger_id')
            ->map(static fn (mixed $at): string => Carbon::parse((string) $at)->format('Y-m-d H:i:s'))
            ->all();
    }

    /**
     * A live daily schedule of a managed flow, due now; the registry knows nothing of it yet.
     */
    private function dueSchedule(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $draft     = FlowDraft::factory()->create(['tenant_id' => $this->tenantId, 'assistant_id' => $assistant->getKey()]);

        FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => $draft->flow_id,
            'version'   => 1,
            'name'      => 'Schedule',
            'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);

        $trigger = new FlowTrigger();
        $trigger->forceFill([
            'id'           => $this->triggerId,
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $assistant->getKey(),
            'flow_id'      => $draft->flow_id,
            'type'         => 'schedule',
            'is_active'    => true,
            'priority'     => 100,
            'config'       => ['cron' => '0 9 * * *', 'timezone' => 'UTC'],
            'next_run_at'  => '2026-11-20 09:00:00',
        ])->save();
    }
}
