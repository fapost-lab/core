<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\Exceptions\SolutionMigrationFailedException;
use App\Domains\Solution\Infrastructure\DatabaseSolutionMigrator;
use App\Domains\Solution\Infrastructure\EloquentSolutionSchemaStore;
use App\Domains\Solution\Services\InstalledSolutions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\InstallsMigratingSolutions;

/**
 * Applies a Solution's migrations in the tenant schema (the one schema of the SQLite run; the lifecycle test covers
 * two real schemas on PostgreSQL): order, journal, footprint, idempotence, and the all-or-nothing run.
 */
final class DatabaseSolutionMigratorTest extends FeatureTestCase
{
    use InstallsMigratingSolutions;

    private const string CREATE_NOTES = '2026_11_16_000001_create_notes_table';

    private const string SEED_NOTES = '2026_11_16_000002_seed_notes';

    private InstalledSolutions $installed;

    private DatabaseSolutionMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrator = new DatabaseSolutionMigrator();
    }

    protected function tearDown(): void
    {
        $this->removeMigratingSolutions();

        parent::tearDown();
    }

    public function test_pending_migrations_run_in_name_order_and_are_journaled_under_the_solution_with_its_footprint(): void
    {
        // Listed newest first on purpose: the seed fails unless the table migration runs before it.
        $this->install(['notes' => [
            self::SEED_NOTES   => self::migrationInserting('notes_items'),
            self::CREATE_NOTES => self::migrationCreating('notes_items'),
        ]]);

        $applied = $this->migrator->migrate($this->solution('notes'));

        $this->assertSame([self::CREATE_NOTES, self::SEED_NOTES], $applied);
        $this->assertTrue(Schema::hasTable('notes_items'));
        $this->assertSame(1, DB::table('notes_items')->count());
        $this->assertSame([self::CREATE_NOTES, self::SEED_NOTES], $this->journal('notes'));
        $this->assertSame(['notes' => self::SEED_NOTES], $this->footprints());
    }

    public function test_a_second_run_applies_nothing_and_keeps_the_footprint(): void
    {
        $this->install(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $this->migrator->migrate($this->solution('notes'));

        $this->assertSame([], $this->migrator->migrate($this->solution('notes')));

        $this->assertSame([self::CREATE_NOTES], $this->journal('notes'));
        $this->assertSame(1, DB::table('solution_schemas')->count());
        $this->assertSame(['notes' => self::CREATE_NOTES], $this->footprints());
    }

    public function test_a_file_added_by_an_upgrade_is_the_only_one_applied_and_moves_the_footprint(): void
    {
        $this->install(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $this->migrator->migrate($this->solution('notes'));

        $this->installed = $this->upgradeMigratingSolution('notes', self::SEED_NOTES, self::migrationInserting('notes_items'));
        $applied         = $this->migrator->migrate($this->solution('notes'));

        $this->assertSame([self::SEED_NOTES], $applied);
        $this->assertSame([self::CREATE_NOTES, self::SEED_NOTES], $this->journal('notes'));
        $this->assertSame(['notes' => self::SEED_NOTES], $this->footprints());
        $this->assertSame(1, DB::table('notes_items')->count(), 'The first migration did not run again.');
    }

    public function test_the_footprint_is_the_newest_name_even_when_an_older_file_arrives_late(): void
    {
        $this->install(['notes' => [self::SEED_NOTES => self::migrationCreating('notes_seeded')]]);
        $this->migrator->migrate($this->solution('notes'));

        $this->installed = $this->upgradeMigratingSolution('notes', self::CREATE_NOTES, self::migrationCreating('notes_items'));
        $applied         = $this->migrator->migrate($this->solution('notes'));

        $this->assertSame([self::CREATE_NOTES], $applied);
        $this->assertSame(['notes' => self::SEED_NOTES], $this->footprints(), 'An older file does not move the footprint back.');
        $this->assertSame(['notes' => InstalledSolution::digestOf([self::CREATE_NOTES, self::SEED_NOTES])], (new EloquentSolutionSchemaStore())->appliedDigests(), 'But the schema is now at the package\'s full set.');
    }

    public function test_two_solutions_shipping_a_file_of_the_same_name_both_run(): void
    {
        $this->install([
            'alpha' => [self::CREATE_NOTES => self::migrationCreating('alpha_items')],
            'beta'  => [self::CREATE_NOTES => self::migrationCreating('beta_items')],
        ]);

        $this->assertSame([self::CREATE_NOTES], $this->migrator->migrate($this->solution('alpha')));
        $this->assertSame([self::CREATE_NOTES], $this->migrator->migrate($this->solution('beta')));

        $this->assertTrue(Schema::hasTable('alpha_items'));
        $this->assertTrue(Schema::hasTable('beta_items'));
        $this->assertSame([self::CREATE_NOTES], $this->journal('alpha'));
        $this->assertSame([self::CREATE_NOTES], $this->journal('beta'));
        $this->assertSame(['alpha' => self::CREATE_NOTES, 'beta' => self::CREATE_NOTES], $this->footprints());
    }

    public function test_a_solution_whose_directory_holds_no_migration_has_no_footprint(): void
    {
        $this->install(['notes' => []]);

        $this->assertSame([], $this->migrator->migrate($this->solution('notes')));

        $this->assertSame(0, DB::table('solution_schemas')->count());
        $this->assertSame(0, DB::table('solution_migrations')->count());
    }

    public function test_a_failing_migration_keeps_nothing_of_the_run(): void
    {
        $this->install(['notes' => [
            self::CREATE_NOTES                => self::migrationCreating('notes_items'),
            '2026_11_16_000002_breaks'        => self::migrationThrowing('no such luck'),
            '2026_11_16_000003_never_reached' => self::migrationCreating('notes_never'),
        ]]);

        try {
            $this->migrator->migrate($this->solution('notes'));
            $this->fail('Expected SolutionMigrationFailedException.');
        } catch (SolutionMigrationFailedException $exception) {
            $this->assertSame('Solution "notes": migration "2026_11_16_000002_breaks" failed: no such luck', $exception->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }

        $this->assertFalse(Schema::hasTable('notes_items'), 'The earlier file of the run is rolled back with it.');
        $this->assertFalse(Schema::hasTable('notes_never'));
        $this->assertSame([], $this->journal('notes'));
        $this->assertSame([], $this->footprints());
    }

    public function test_a_failed_upgrade_leaves_what_the_earlier_run_kept(): void
    {
        $this->install(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $this->migrator->migrate($this->solution('notes'));

        $this->installed = $this->upgradeMigratingSolution('notes', self::SEED_NOTES, self::migrationThrowing());

        try {
            $this->migrator->migrate($this->solution('notes'));
            $this->fail('Expected SolutionMigrationFailedException.');
        } catch (SolutionMigrationFailedException) {
            // expected
        }

        $this->assertTrue(Schema::hasTable('notes_items'));
        $this->assertSame([self::CREATE_NOTES], $this->journal('notes'));
        $this->assertSame(['notes' => self::CREATE_NOTES], $this->footprints());
    }

    public function test_a_failure_in_one_solution_does_not_undo_another(): void
    {
        $this->install([
            'alpha' => [self::CREATE_NOTES => self::migrationCreating('alpha_items')],
            'beta'  => [self::CREATE_NOTES => self::migrationThrowing()],
        ]);
        $this->migrator->migrate($this->solution('alpha'));

        try {
            $this->migrator->migrate($this->solution('beta'));
            $this->fail('Expected SolutionMigrationFailedException.');
        } catch (SolutionMigrationFailedException) {
            // expected
        }

        $this->assertTrue(Schema::hasTable('alpha_items'));
        $this->assertSame(['alpha' => self::CREATE_NOTES], $this->footprints());
    }

    public function test_a_file_that_does_not_return_a_migration_is_refused(): void
    {
        $this->install(['notes' => [self::CREATE_NOTES => self::notAMigration()]]);

        try {
            $this->migrator->migrate($this->solution('notes'));
            $this->fail('Expected SolutionMigrationFailedException.');
        } catch (SolutionMigrationFailedException $exception) {
            $this->assertStringContainsString('the file must return an instance of Illuminate\\Database\\Migrations\\Migration', $exception->getMessage());
            $this->assertStringContainsString(self::CREATE_NOTES, $exception->getMessage());
        }

        $this->assertSame([], $this->footprints());
    }

    public function test_a_migration_that_names_a_connection_is_refused_before_it_runs(): void
    {
        $this->install(['notes' => [self::CREATE_NOTES => self::migrationOnConnection('landlord')]]);

        try {
            $this->migrator->migrate($this->solution('notes'));
            $this->fail('Expected SolutionMigrationFailedException.');
        } catch (SolutionMigrationFailedException $exception) {
            $this->assertStringContainsString('must not name a connection', $exception->getMessage());
        }

        $this->assertSame([], $this->journal('notes'));
        $this->assertSame([], $this->footprints());
    }

    public function test_a_migration_without_up_is_refused(): void
    {
        $this->install(['notes' => [self::CREATE_NOTES => self::migrationWithoutUp()]]);

        $this->expectException(SolutionMigrationFailedException::class);
        $this->expectExceptionMessage('the migration has no up()');

        $this->migrator->migrate($this->solution('notes'));
    }

    public function test_a_file_that_is_not_valid_php_is_a_failed_migration_and_not_a_crash(): void
    {
        $this->install(['notes' => [self::CREATE_NOTES => "<?php\n\nreturn new class { \n"]]);

        $this->expectException(SolutionMigrationFailedException::class);

        $this->migrator->migrate($this->solution('notes'));
    }

    public function test_the_ids_of_the_rows_it_writes_are_uuids_as_the_uuid_columns_require(): void
    {
        $this->install(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);

        $this->migrator->migrate($this->solution('notes'));

        // PostgreSQL rejects anything else in a `uuid` column; SQLite would let a 26-character ULID through.
        $this->assertTrue(Str::isUuid((string) DB::table('solution_migrations')->value('id')), 'solution_migrations.id');
        $this->assertTrue(Str::isUuid((string) DB::table('solution_schemas')->value('id')), 'solution_schemas.id');
    }

    public function test_a_run_records_the_digest_of_every_file_of_the_package_and_a_second_run_keeps_it(): void
    {
        $this->install(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items'), self::SEED_NOTES => self::migrationInserting('notes_items')]]);

        $this->migrator->migrate($this->solution('notes'));
        $this->migrator->migrate($this->solution('notes'));

        $this->assertSame(
            InstalledSolution::digestOf([self::CREATE_NOTES, self::SEED_NOTES]),
            DB::table('solution_schemas')->where('solution_id', 'notes')->value('migrations_digest'),
        );
        $this->assertSame(1, DB::table('solution_schemas')->count());
    }

    public function test_a_failure_outside_the_migration_itself_is_reported_as_a_failed_run_and_keeps_nothing(): void
    {
        // The migration succeeds, then takes the journal away: writing its journal row fails.
        $this->install(['notes' => [self::CREATE_NOTES => self::migrationRunning("Schema::drop('solution_migrations');")]]);

        try {
            $this->migrator->migrate($this->solution('notes'));
            $this->fail('Expected SolutionMigrationFailedException.');
        } catch (SolutionMigrationFailedException $exception) {
            $this->assertStringContainsString('migration "(run)" failed', $exception->getMessage());
            $this->assertNotNull($exception->getPrevious());
        }

        $this->assertTrue(Schema::hasTable('solution_migrations'), 'The whole run, the drop included, was rolled back.');
        $this->assertSame([], $this->footprints());
    }

    public function test_the_schema_store_reads_the_footprint_of_every_solution_in_the_schema(): void
    {
        $this->install([
            'alpha' => [self::CREATE_NOTES => self::migrationCreating('alpha_items')],
            'beta'  => [self::CREATE_NOTES => self::migrationCreating('beta_items'), self::SEED_NOTES => self::migrationInserting('beta_items')],
        ]);

        $this->assertSame([], (new EloquentSolutionSchemaStore())->appliedDigests());

        $this->migrator->migrate($this->solution('alpha'));
        $this->migrator->migrate($this->solution('beta'));

        $this->assertSame(
            ['alpha' => InstalledSolution::digestOf([self::CREATE_NOTES]), 'beta' => InstalledSolution::digestOf([self::CREATE_NOTES, self::SEED_NOTES])],
            (new EloquentSolutionSchemaStore())->appliedDigests(),
        );
    }

    /**
     * @param  array<string, array<string, string>>  $solutions
     */
    private function install(array $solutions): void
    {
        $this->installed = $this->installMigratingSolutions($solutions);
    }

    private function solution(string $id): InstalledSolution
    {
        return $this->installed->find($id) ?? $this->fail('Solution ' . $id . ' is not installed.');
    }

    /**
     * @return list<string> the journaled migrations of the Solution, sorted
     */
    private function journal(string $solutionId): array
    {
        /** @var list<string> $names */
        $names = DB::table('solution_migrations')->where('solution_id', $solutionId)->orderBy('migration')->pluck('migration')->all();

        return $names;
    }

    /**
     * @return array<string, string> newest applied migration by Solution id
     */
    private function footprints(): array
    {
        /** @var array<string, string> $latest */
        $latest = DB::table('solution_schemas')->orderBy('solution_id')->pluck('latest_migration', 'solution_id')->all();

        return $latest;
    }
}
