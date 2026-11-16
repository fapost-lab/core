<?php

declare(strict_types=1);

namespace App\Domains\Solution\Infrastructure;

use App\Domains\Solution\Contracts\SolutionMigratorInterface;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\Exceptions\SolutionMigrationFailedException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a Solution's migrations on the default connection, which inside `runForTenant()` is the tenant's schema.
 *
 * Laravel's migrator is not used: it logs into Core's `migrations` table, where two Solutions could ship a file of
 * the same name and their batches would mix with Core's, and it runs `down()`. Here a Solution's migrations are
 * journaled in `solution_migrations` under its id, and the whole run — every pending migration, the journal and the
 * footprint in `solution_schemas` — is one transaction. On PostgreSQL an advisory lock per schema and Solution makes a
 * concurrent run (an activation and a queued preparation) wait and then find nothing pending.
 *
 * A migration file must return an instance of {@see Migration} (an anonymous class, as Laravel generates) that names
 * no connection of its own: a Solution keeps tables only in the tenant's schema.
 */
final class DatabaseSolutionMigrator implements SolutionMigratorInterface
{
    public const string JOURNAL_TABLE = 'solution_migrations';

    public function migrate(InstalledSolution $solution): array
    {
        $directory = $solution->migrationsPath();

        if (null === $directory || !is_dir($directory)) {
            return [];
        }

        /** @var array<string, string> $files path by migration name */
        $files = [];

        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $files[basename($file, '.php')] = $file;
        }

        ksort($files, SORT_STRING);

        if ([] === $files) {
            return [];
        }

        $connection = DB::connection();

        if (!$connection instanceof Connection) {
            return [];
        }

        try {
            return $this->run($connection, $solution, $files);
        } catch (SolutionMigrationFailedException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // The journal, the footprint or the lock failed: nothing of the run was kept either.
            throw SolutionMigrationFailedException::because($solution->id, '(run)', $exception->getMessage(), $exception);
        }
    }

    /**
     * @param  array<string, string>  $files  path by migration name, in name order
     *
     * @return list<string>
     */
    private function run(Connection $connection, InstalledSolution $solution, array $files): array
    {
        return $connection->transaction(function () use ($connection, $solution, $files): array {
            $this->lock($connection, $solution->id);

            /** @var list<string> $ran */
            $ran = $connection->table(self::JOURNAL_TABLE)->where('solution_id', $solution->id)->pluck('migration')->all();

            $applied = [];

            foreach (array_diff_key($files, array_flip($ran)) as $name => $file) {
                $this->runOne($connection, $solution->id, $name, $file);
                $applied[] = $name;
            }

            $all = [...$ran, ...$applied];
            sort($all, SORT_STRING);
            $now = CarbonImmutable::now('UTC');

            $connection->table(EloquentSolutionSchemaStore::TABLE)->upsert(
                [[
                    'id'                => mb_strtolower(Str::ulid()->toRfc4122()),
                    'solution_id'       => $solution->id,
                    'latest_migration'  => $all[array_key_last($all)],
                    'migrations_digest' => InstalledSolution::digestOf(array_keys($files)),
                    'migrated_at'       => $now,
                ]],
                ['solution_id'],
                ['latest_migration', 'migrations_digest', 'migrated_at'],
            );

            return $applied;
        });
    }

    private function runOne(Connection $connection, string $solutionId, string $name, string $file): void
    {
        try {
            $migration = (static fn (string $path): mixed => require $path)($file);
        } catch (Throwable $exception) {
            throw SolutionMigrationFailedException::because($solutionId, $name, $exception->getMessage(), $exception);
        }

        if (!$migration instanceof Migration) {
            throw SolutionMigrationFailedException::because($solutionId, $name, 'the file must return an instance of ' . Migration::class);
        }

        if (null !== $migration->getConnection()) {
            throw SolutionMigrationFailedException::because($solutionId, $name, 'a Solution migration must not name a connection; it runs in the tenant schema');
        }

        if (!method_exists($migration, 'up')) {
            throw SolutionMigrationFailedException::because($solutionId, $name, 'the migration has no up()');
        }

        try {
            $migration->up();
        } catch (Throwable $exception) {
            throw SolutionMigrationFailedException::because($solutionId, $name, $exception->getMessage(), $exception);
        }

        $connection->table(self::JOURNAL_TABLE)->insert([
            'id'          => mb_strtolower(Str::ulid()->toRfc4122()),
            'solution_id' => $solutionId,
            'migration'   => $name,
            'ran_at'      => CarbonImmutable::now('UTC'),
        ]);
    }

    private function lock(Connection $connection, string $solutionId): void
    {
        if ('pgsql' !== $connection->getDriverName()) {
            return;
        }

        $connection->select("select pg_advisory_xact_lock(hashtext(current_schema() || ':solution-schema:' || ?))", [$solutionId]);
    }
}
