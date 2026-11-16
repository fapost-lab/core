<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Solution;

use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Solution\Services\SolutionSchemas;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Infrastructure\Solution\ContainerSolutionAvailability;
use App\Infrastructure\Solution\SolutionPreparation;
use App\Jobs\Solution\PrepareSolutionSchemaJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\FeatureTestCase;
use Tests\Support\InstallsFakeSolutions;
use Tests\Support\InstallsMigratingSolutions;

/**
 * The answer a flow step gets about a Solution's tables: ready, or "not yet" with the preparation queued. DDL never
 * runs on the asking side.
 */
final class SolutionPreparationTest extends FeatureTestCase
{
    use InstallsFakeSolutions;
    use InstallsMigratingSolutions;

    private const string CREATE_NOTES = '2026_11_16_000001_create_notes_table';

    private TenantInterface $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($tenant);
        $this->tenant = $tenant;

        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->removeMigratingSolutions();
        $this->removeFakeSolutions();

        parent::tearDown();
    }

    public function test_a_solution_whose_tables_are_missing_is_not_ready_and_its_preparation_is_queued_for_the_tenant(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);

        $ready = $this->inTenant(fn (): bool => $this->app->make(SolutionPreparation::class)->isReadyOrPrepare('notes'));

        $this->assertFalse($ready);
        Bus::assertDispatchedTimes(PrepareSolutionSchemaJob::class, 1);
        Bus::assertDispatched(
            PrepareSolutionSchemaJob::class,
            fn (PrepareSolutionSchemaJob $job): bool => $job->tenantId === $this->tenant->getId()
                && 'notes' === $job->solutionId
                && 'redis-long' === $job->connection
                && 'solutions.migrate' === $job->queue,
        );
        $this->assertFalse(Schema::hasTable('notes_items'), 'Asking never runs DDL.');
        $this->assertSame(0, DB::table('solution_schemas')->count());
    }

    public function test_a_solution_whose_tables_are_current_is_ready_and_nothing_is_queued(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $this->inTenant(fn () => $this->app->make(SolutionSchemas::class)->bringCurrent('notes'));

        $ready = $this->inTenant(fn (): bool => $this->app->make(SolutionPreparation::class)->isReadyOrPrepare('notes'));

        $this->assertTrue($ready);
        Bus::assertNothingDispatched();
    }

    public function test_a_solution_without_migrations_is_ready_and_nothing_is_queued(): void
    {
        $this->installSolutions('feedback');

        $ready = $this->inTenant(fn (): bool => $this->app->make(SolutionPreparation::class)->isReadyOrPrepare('feedback'));

        $this->assertTrue($ready);
        Bus::assertNothingDispatched();
    }

    public function test_outside_a_tenant_nothing_is_ready_and_nothing_is_queued(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);

        $this->assertFalse($this->app->make(SolutionPreparation::class)->isReadyOrPrepare('notes'));

        Bus::assertNothingDispatched();
    }

    public function test_the_flow_availability_answers_readiness_through_the_preparation(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $availability = $this->app->make(SolutionAvailabilityInterface::class);

        $this->assertInstanceOf(ContainerSolutionAvailability::class, $availability);
        $this->assertFalse($this->inTenant(fn (): bool => $availability->isReady('notes')));
        Bus::assertDispatchedTimes(PrepareSolutionSchemaJob::class, 1);

        $this->inTenant(fn () => $this->app->make(SolutionSchemas::class)->bringCurrent('notes'));
        $this->app->forgetScopedInstances();

        $this->assertTrue($this->inTenant(fn (): bool => $availability->isReady('notes')));
        Bus::assertDispatchedTimes(PrepareSolutionSchemaJob::class, 1);
    }

    private function inTenant(callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($this->tenant, $callback(...));
    }
}
