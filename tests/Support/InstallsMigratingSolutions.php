<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use LogicException;

/**
 * Installs fixture Solutions that ship tenant migrations (`extra.fapost.migrations` = `database/migrations`) into a
 * throwaway vendor tree and binds the catalog over it. Each Solution is a copy of the `feedback` fixture manifest under
 * its own id; its migration files are given as PHP source by file name, so a test writes exactly the schema it needs.
 * Call {@see removeMigratingSolutions()} in tearDown.
 */
trait InstallsMigratingSolutions
{
    private ?FakeVendor $migratingVendor = null;

    /**
     * A migration that creates a table with a primary key and a title.
     */
    protected static function migrationCreating(string $table): string
    {
        return self::migrationSource(<<<PHP
            Schema::create('{$table}', static function (Blueprint \$table): void {
                \$table->uuid('id')->primary();
                \$table->string('title')->nullable();
            });
            PHP);
    }

    /**
     * A migration that inserts one row into a table an earlier migration created: it fails when it runs first.
     */
    protected static function migrationInserting(string $table): string
    {
        return self::migrationSource("DB::table('{$table}')->insert(['id' => (string) Str::uuid(), 'title' => 'seed']);");
    }

    protected static function migrationThrowing(string $message = 'boom'): string
    {
        return self::migrationSource("throw new RuntimeException('{$message}');");
    }

    /**
     * A file that returns something that is not a migration.
     */
    protected static function notAMigration(): string
    {
        return "<?php\n\nreturn 'not a migration';\n";
    }

    /**
     * A migration that names a connection of its own.
     */
    protected static function migrationOnConnection(string $connection): string
    {
        return <<<PHP
            <?php

            use Illuminate\Database\Migrations\Migration;

            return new class () extends Migration {
                protected \$connection = '{$connection}';

                public function up(): void
                {
                }
            };
            PHP;
    }

    /**
     * A migration class that has no `up()`.
     */
    protected static function migrationWithoutUp(): string
    {
        return "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\n\nreturn new class () extends Migration {\n};\n";
    }

    /**
     * A migration whose `up()` runs the given PHP statements (`Schema`, `DB`, `Str` and `Blueprint` are imported).
     */
    protected static function migrationRunning(string $upBody): string
    {
        return self::migrationSource($upBody);
    }

    /**
     * @param  array<string, array<string, string>>  $solutions  migration source by file name (without `.php`), by Solution id
     */
    protected function installMigratingSolutions(array $solutions): InstalledSolutions
    {
        $this->removeMigratingSolutions();
        $this->migratingVendor = new FakeVendor();

        foreach ($solutions as $id => $files) {
            $this->migratingVendor->package(FakeVendor::solutionComposer(
                ['id' => $id, 'name' => ucfirst($id), 'actions' => [$id . '.run'], 'migrations' => 'database/migrations'],
                ['name' => 'acme/fapost-' . $id],
            ));

            mkdir($this->migratingPackageDirectory($id) . '/database/migrations', 0o777, true);

            foreach ($files as $name => $source) {
                $this->writeSolutionMigration($id, $name, $source);
            }
        }

        $this->migratingVendor->write();

        return $this->bindMigratingCatalog();
    }

    /**
     * Ships one more migration with an installed Solution, as an upgraded package would, and reads the catalog again
     * (a new process would too: the package list is the image's).
     */
    protected function upgradeMigratingSolution(string $id, string $name, string $source): InstalledSolutions
    {
        $this->writeSolutionMigration($id, $name, $source);

        return $this->bindMigratingCatalog();
    }

    protected function removeMigratingSolutions(): void
    {
        $this->migratingVendor?->cleanup();
        $this->migratingVendor = null;
    }

    /**
     * Takes a migration file out of an installed Solution's package, as an older package version would lack it, and
     * reads the catalog again.
     */
    protected function removeSolutionMigration(string $id, string $name): InstalledSolutions
    {
        unlink($this->migratingPackageDirectory($id) . '/database/migrations/' . $name . '.php');

        return $this->bindMigratingCatalog();
    }

    private static function migrationSource(string $upBody): string
    {
        return <<<PHP
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\Facades\Schema;
            use Illuminate\Support\Str;

            return new class () extends Migration {
                public function up(): void
                {
                    {$upBody}
                }
            };
            PHP;
    }

    private function writeSolutionMigration(string $id, string $name, string $source): void
    {
        file_put_contents($this->migratingPackageDirectory($id) . '/database/migrations/' . $name . '.php', $source);
    }

    private function migratingPackageDirectory(string $id): string
    {
        return ($this->migratingVendor ?? throw new LogicException('No migrating Solutions are installed.'))->path . '/acme/fapost-' . $id;
    }

    private function bindMigratingCatalog(): InstalledSolutions
    {
        $vendor    = $this->migratingVendor ?? throw new LogicException('No migrating Solutions are installed.');
        $installed = new InstalledSolutions(
            new SolutionCache(dirname($vendor->path) . '/fapost-solutions.php'),
            new SolutionDiscoverer(
                new InstalledPackagesReader($vendor->path),
                new SolutionManifestValidator(new ManifestParser()),
            ),
        );

        $this->app->instance(InstalledSolutions::class, $installed);
        $this->app->forgetInstance(ActiveSolutions::class);

        return $installed;
    }
}
