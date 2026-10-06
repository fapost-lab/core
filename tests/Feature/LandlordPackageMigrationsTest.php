<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * An extension package delivers landlord migrations with loadMigrationsFrom(); the platform
 * command `migrate --database=landlord` must apply them and `migrate:smart` must count them.
 */
final class LandlordPackageMigrationsTest extends FeatureTestCase
{
    private const MIGRATION = '2099_01_01_000001_create_fixture_landlord_table';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/landlord-package-' . uniqid();
        File::makeDirectory($this->directory);
        File::put($this->directory . '/' . self::MIGRATION . '.php', <<<'PHP'
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                protected $connection = 'landlord';

                public function up(): void
                {
                    Schema::create('fixture_items', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('fixture_items');
                }
            };
            PHP);

        // Exactly what a package service provider does in boot().
        $this->app->make('migrator')->path($this->directory);
    }

    protected function tearDown(): void
    {
        Schema::connection('landlord')->dropIfExists('fixture_items');
        DB::connection('landlord')->table('migrations')->where('migration', self::MIGRATION)->delete();
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_package_landlord_migration_is_pending_and_then_applied_by_the_platform_command(): void
    {
        Artisan::call('migrate:smart', ['--landlord' => true]);
        $this->assertStringContainsString('Landlord pending: 1', Artisan::output());
        $this->assertFalse(Schema::connection('landlord')->hasTable('fixture_items'));

        $exitCode = Artisan::call('migrate', ['--database' => 'landlord', '--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(Schema::connection('landlord')->hasTable('fixture_items'));
        $this->assertTrue(
            DB::connection('landlord')->table('migrations')->where('migration', self::MIGRATION)->exists(),
        );

        Artisan::call('migrate:smart', ['--landlord' => true]);
        $this->assertStringContainsString('Landlord pending: 0', Artisan::output());
    }

    public function test_plain_migrate_puts_a_landlord_migration_on_landlord_not_on_default(): void
    {
        // The default connection's log knows nothing of the landlord tables already built by the
        // harness, so mark every other migration as run and leave only the package's pending.
        $migrator = $this->app->make('migrator');
        foreach (array_keys($migrator->getMigrationFiles([...$migrator->paths(), database_path('migrations')])) as $name) {
            if (self::MIGRATION !== $name && ! DB::table('migrations')->where('migration', $name)->exists()) {
                DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);
            }
        }

        $exitCode = Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(Schema::connection('landlord')->hasTable('fixture_items'));
        $this->assertFalse(Schema::connection(config('database.default'))->hasTable('fixture_items'));
    }

    public function test_the_landlord_command_does_not_create_the_tenant_settings_table(): void
    {
        Schema::connection('landlord')->dropIfExists('settings');

        Artisan::call('migrate', ['--database' => 'landlord', '--force' => true]);

        $this->assertFalse(Schema::connection('landlord')->hasTable('settings'));

        $migrationFiles = $this->app->make('migrator')->getMigrationFiles($this->app->make('migrator')->paths());
        $this->assertNotContains('2026_03_23_175815_create_settings_table', array_keys($migrationFiles));
    }
}
