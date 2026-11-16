<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\Contracts\SolutionMigratorInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\Enums\SolutionActivationStatus;
use App\Domains\Solution\Exceptions\SolutionMigrationFailedException;
use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Solution\Infrastructure\DatabaseSolutionMigrator;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\SolutionSchemas;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Fapost\Foundation\Solution\Enums\SolutionGrantSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeSolutionEntitlement;
use Tests\Support\InstallsFakeSolutions;
use Tests\Support\InstallsMigratingSolutions;

/**
 * {@see SolutionSchemas} and the readiness question {@see ActiveSolutions::isReady()} answers from the footprint it
 * leaves, for one tenant schema.
 */
final class SolutionSchemasTest extends FeatureTestCase
{
    use InstallsFakeSolutions;
    use InstallsMigratingSolutions;

    private const string CREATE_NOTES = '2026_11_16_000001_create_notes_table';

    private const string ADD_ROWS = '2026_11_16_000002_add_rows';

    protected function tearDown(): void
    {
        $this->removeMigratingSolutions();
        $this->removeFakeSolutions();

        parent::tearDown();
    }

    public function test_bringing_a_solution_current_applies_its_migrations_and_makes_it_ready(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);

        $this->inTenant(function (): void {
            $this->assertFalse($this->app->make(ActiveSolutions::class)->isReady('notes'), 'Nothing applied yet.');

            $applied = $this->app->make(SolutionSchemas::class)->bringCurrent('notes');

            $this->assertSame([self::CREATE_NOTES], $applied);
            $this->assertTrue($this->app->make(ActiveSolutions::class)->isReady('notes'), 'The memo was dropped with the write.');
        });

        $this->assertTrue(Schema::hasTable('notes_items'));
    }

    public function test_a_schema_that_is_current_does_not_reach_the_migrator_again(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $migrator = new class () implements SolutionMigratorInterface {
            public int $calls = 0;

            public function migrate(InstalledSolution $solution): array
            {
                ++$this->calls;

                return (new DatabaseSolutionMigrator())->migrate($solution);
            }
        };
        $this->app->instance(SolutionMigratorInterface::class, $migrator);

        $this->inTenant(function () use ($migrator): void {
            $schemas = $this->app->make(SolutionSchemas::class);

            $this->assertSame([self::CREATE_NOTES], $schemas->bringCurrent('notes'));
            $this->assertSame([], $schemas->bringCurrent('notes'));
            $this->assertSame(1, $migrator->calls);
        });
    }

    public function test_an_upgrade_makes_a_ready_solution_not_ready_until_it_is_brought_current_again(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $this->inTenant(fn () => $this->app->make(SolutionSchemas::class)->bringCurrent('notes'));

        $this->upgradeMigratingSolution('notes', self::ADD_ROWS, self::migrationInserting('notes_items'));

        $this->inTenant(function (): void {
            $this->assertFalse($this->app->make(ActiveSolutions::class)->isReady('notes'));

            $this->assertSame([self::ADD_ROWS], $this->app->make(SolutionSchemas::class)->bringCurrent('notes'));
            $this->assertTrue($this->app->make(ActiveSolutions::class)->isReady('notes'));
        });
    }

    public function test_a_solution_that_is_not_installed_cannot_be_brought_current(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);

        $this->inTenant(function (): void {
            try {
                $this->app->make(SolutionSchemas::class)->bringCurrent('ghost');
                $this->fail('Expected SolutionNotInstalledException.');
            } catch (SolutionNotInstalledException $exception) {
                $this->assertStringContainsString('ghost', $exception->getMessage());
            }
        });
    }

    public function test_a_failed_run_leaves_the_solution_not_ready_and_the_memo_fresh(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationThrowing()]]);

        $this->inTenant(function (): void {
            $this->assertFalse($this->app->make(ActiveSolutions::class)->isReady('notes'));

            try {
                $this->app->make(SolutionSchemas::class)->bringCurrent('notes');
                $this->fail('Expected SolutionMigrationFailedException.');
            } catch (SolutionMigrationFailedException) {
                // expected
            }

            $this->assertFalse($this->app->make(ActiveSolutions::class)->isReady('notes'));
        });
    }

    public function test_a_file_that_sorts_before_an_applied_one_and_arrives_later_makes_the_solution_not_ready_and_is_applied(): void
    {
        $this->installMigratingSolutions(['notes' => [self::ADD_ROWS => self::migrationCreating('notes_late')]]);
        $this->inTenant(fn () => $this->app->make(SolutionSchemas::class)->bringCurrent('notes'));

        // A branch merged in later ships a migration dated before the one already applied.
        $this->upgradeMigratingSolution('notes', self::CREATE_NOTES, self::migrationCreating('notes_items'));

        $this->inTenant(function (): void {
            $this->assertFalse($this->app->make(ActiveSolutions::class)->isReady('notes'));

            $this->assertSame([self::CREATE_NOTES], $this->app->make(SolutionSchemas::class)->bringCurrent('notes'));

            $this->assertTrue($this->app->make(ActiveSolutions::class)->isReady('notes'));
            $this->assertSame([], $this->app->make(SolutionSchemas::class)->bringCurrent('notes'));
        });
        $this->assertTrue(Schema::hasTable('notes_items'));
        $this->assertSame(self::ADD_ROWS, DB::table('solution_schemas')->where('solution_id', 'notes')->value('latest_migration'), 'The footprint is still the newest name.');
    }

    public function test_a_package_moved_back_to_fewer_files_is_not_ready_until_a_run_that_applies_nothing_records_the_smaller_set(): void
    {
        $this->installMigratingSolutions(['notes' => [
            self::CREATE_NOTES => self::migrationCreating('notes_items'),
            self::ADD_ROWS     => self::migrationCreating('notes_more'),
        ]]);
        $this->inTenant(fn () => $this->app->make(SolutionSchemas::class)->bringCurrent('notes'));

        $this->removeSolutionMigration('notes', self::ADD_ROWS);

        $this->inTenant(function (): void {
            $this->assertFalse($this->app->make(ActiveSolutions::class)->isReady('notes'));

            $this->assertSame([], $this->app->make(SolutionSchemas::class)->bringCurrent('notes'), 'Nothing is pending.');

            $this->assertTrue($this->app->make(ActiveSolutions::class)->isReady('notes'));
        });
        $this->assertTrue(Schema::hasTable('notes_more'), 'Nothing is rolled back.');
    }

    public function test_ids_to_keep_current_are_the_installed_solutions_with_a_footprint_or_stored_active_sorted(): void
    {
        $this->installMigratingSolutions([
            'zeta'  => [self::CREATE_NOTES => self::migrationCreating('zeta_items')],
            'alpha' => [self::CREATE_NOTES => self::migrationCreating('alpha_items')],
            'beta'  => [self::CREATE_NOTES => self::migrationCreating('beta_items')],
            'gamma' => [self::CREATE_NOTES => self::migrationCreating('gamma_items')],
        ]);

        $this->inTenant(function (): void {
            $schemas = $this->app->make(SolutionSchemas::class);

            $this->assertSame([], $schemas->idsToKeepCurrent(), 'Installed is not enough.');

            $schemas->bringCurrent('zeta');
            $schemas->bringCurrent('alpha');
            $this->app->make(SolutionActivationStoreInterface::class)->change('gamma', SolutionActivationStatus::Active, ActivationActor::console(), null);

            $this->assertSame(['alpha', 'gamma', 'zeta'], $schemas->idsToKeepCurrent(), 'A footprint, or stored active without one.');
        });
    }

    public function test_a_granted_solution_without_a_footprint_is_not_one_to_keep_current_and_the_operator_is_not_asked(): void
    {
        $this->installMigratingSolutions(['alpha' => [self::CREATE_NOTES => self::migrationCreating('alpha_items')]]);
        $operator          = new FakeSolutionEntitlement();
        $operator->default = SolutionEntitlement::grants([new SolutionGrant('alpha', SolutionGrantSource::Plan)]);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);

        $this->inTenant(fn () => $this->assertSame([], $this->app->make(SolutionSchemas::class)->idsToKeepCurrent()));
        $this->assertSame([], $operator->asked);
    }

    public function test_a_stored_inactive_solution_without_a_footprint_is_not_one_to_keep_current(): void
    {
        $this->installMigratingSolutions(['alpha' => [self::CREATE_NOTES => self::migrationCreating('alpha_items')]]);

        $this->inTenant(function (): void {
            $store = $this->app->make(SolutionActivationStoreInterface::class);
            $store->change('alpha', SolutionActivationStatus::Active, ActivationActor::console(), null);
            $store->change('alpha', SolutionActivationStatus::Inactive, ActivationActor::console(), null);

            $this->assertSame([], $this->app->make(SolutionSchemas::class)->idsToKeepCurrent());
        });
    }

    public function test_a_footprint_of_a_solution_that_is_no_longer_installed_is_not_one_to_keep_current(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $this->inTenant(fn () => $this->app->make(SolutionSchemas::class)->bringCurrent('notes'));

        $this->installMigratingSolutions(['other' => []]);

        $this->inTenant(fn () => $this->assertSame([], $this->app->make(SolutionSchemas::class)->idsToKeepCurrent()));
    }

    public function test_a_solution_without_migrations_is_always_ready(): void
    {
        $this->installSolutions('feedback');

        $this->inTenant(function (): void {
            $this->assertTrue($this->app->make(ActiveSolutions::class)->isReady('feedback'));
            $this->assertSame([], $this->app->make(SolutionSchemas::class)->bringCurrent('feedback'));
        });

        $this->assertSame(0, DB::table('solution_schemas')->count());
    }

    public function test_a_solution_that_is_not_installed_is_never_ready(): void
    {
        $this->installSolutions('feedback');

        $this->inTenant(fn () => $this->assertFalse($this->app->make(ActiveSolutions::class)->isReady('ghost')));
    }

    public function test_readiness_is_read_once_per_scope_and_follows_the_schema_after_the_memo_is_dropped(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);

        $this->inTenant(function (): void {
            $active = $this->app->make(ActiveSolutions::class);
            $this->assertFalse($active->isReady('notes'));

            // Another process migrates meanwhile: this scope still has its first answer.
            DB::table('solution_schemas')->insert(['id' => (string) Str::uuid(), 'solution_id' => 'notes', 'latest_migration' => self::CREATE_NOTES, 'migrations_digest' => InstalledSolution::digestOf([self::CREATE_NOTES]), 'migrated_at' => now()]);
            $this->assertFalse($active->isReady('notes'));

            $active->forget('tenant-one');
            $this->assertTrue($active->isReady('notes'));
        });
    }

    private function inTenant(callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant(new RuntimeTenant('tenant-one', 'main', 'one'), $callback(...));
    }
}
