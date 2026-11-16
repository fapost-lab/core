<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Solution;

use App\Domains\Solution\Exceptions\SolutionMigrationFailedException;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Jobs\Solution\PrepareSolutionSchemaJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\FeatureTestCase;
use Tests\Support\InstallsMigratingSolutions;

final class PrepareSolutionSchemaJobTest extends FeatureTestCase
{
    use InstallsMigratingSolutions;

    private const string CREATE_NOTES = '2026_11_16_000001_create_notes_table';

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($tenant);
        $this->tenantId = $tenant->getId();
    }

    protected function tearDown(): void
    {
        $this->removeMigratingSolutions();

        parent::tearDown();
    }

    public function test_it_brings_the_solutions_tables_in_the_tenants_schema_current(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);

        $this->app->call([new PrepareSolutionSchemaJob($this->tenantId, 'notes'), 'handle']);

        $this->assertTrue(Schema::hasTable('notes_items'));
        $this->assertSame(['notes' => self::CREATE_NOTES], DB::table('solution_schemas')->pluck('latest_migration', 'solution_id')->all());
        $this->inMainTenant(fn () => $this->assertTrue($this->app->make(ActiveSolutions::class)->isReady('notes')));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $job = new PrepareSolutionSchemaJob($this->tenantId, 'notes');

        $this->app->call([$job, 'handle']);
        $this->app->call([$job, 'handle']);

        $this->assertSame(1, DB::table('solution_migrations')->count());
    }

    public function test_a_tenant_that_no_longer_exists_is_nothing_to_prepare(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);

        $this->app->call([new PrepareSolutionSchemaJob('00000000-0000-0000-0000-00000000dead', 'notes'), 'handle']);

        $this->assertFalse(Schema::hasTable('notes_items'));
        $this->assertSame(0, DB::table('solution_schemas')->count());
    }

    public function test_a_solution_removed_from_the_image_since_the_step_asked_is_nothing_to_prepare(): void
    {
        $this->installMigratingSolutions(['other' => []]);

        $this->app->call([new PrepareSolutionSchemaJob($this->tenantId, 'notes'), 'handle']);

        $this->assertSame(0, DB::table('solution_schemas')->count());
    }

    public function test_a_failing_migration_fails_the_job_so_that_it_is_retried_and_keeps_nothing(): void
    {
        $this->installMigratingSolutions(['notes' => [
            self::CREATE_NOTES         => self::migrationCreating('notes_items'),
            '2026_11_16_000002_breaks' => self::migrationThrowing(),
        ]]);

        try {
            $this->app->call([new PrepareSolutionSchemaJob($this->tenantId, 'notes'), 'handle']);
            $this->fail('Expected SolutionMigrationFailedException.');
        } catch (SolutionMigrationFailedException) {
            // expected
        }

        $this->assertFalse(Schema::hasTable('notes_items'));
        $this->assertSame(0, DB::table('solution_schemas')->count());
    }

    public function test_it_is_unique_per_tenant_and_solution_and_runs_on_its_own_queue(): void
    {
        $job = new PrepareSolutionSchemaJob($this->tenantId, 'notes');

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame($this->tenantId . ':notes', $job->uniqueId());
        $this->assertNotSame($job->uniqueId(), (new PrepareSolutionSchemaJob($this->tenantId, 'other'))->uniqueId());
        $this->assertNotSame($job->uniqueId(), (new PrepareSolutionSchemaJob('another-tenant', 'notes'))->uniqueId());
        $this->assertSame('redis-long', $job->connection);
        $this->assertSame('solutions.migrate', $job->queue);
    }

    private function inMainTenant(callable $callback): mixed
    {
        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($tenant);

        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback(...));
    }
}
