<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Support\TenantHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base class for feature tests that require tenant context.
 *
 * Runs landlord migrations on the landlord connection and seeds a test tenant
 * so that TenancyMiddleware can resolve it during HTTP tests.
 *
 * Migrations run once per distinct {@see migrateFreshUsing()} signature per process,
 * not per test: both the tenant (default) and the landlord connection are transacted
 * and rolled back between tests, and their in-memory PDO handles are cached by
 * {@see RefreshDatabase::restoreInMemoryDatabase()}. A subclass that overrides
 * {@see migrateFreshUsing()} gets its own signature and therefore its own fresh
 * migration when it is reached.
 */
abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase {
        migrateDatabases as private migrateDefaultConnection;
    }

    /**
     * Migration signature this process last migrated for, or null before the first run.
     */
    private static ?string $migratedSignature = null;

    /**
     * Both connections are transacted so writes to landlord (extra tenants, status
     * changes) roll back between tests instead of leaking into the next one.
     *
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [config('database.default'), 'landlord'];
    }

    /**
     * Force a fresh migration only when the migration plan differs from the one
     * currently applied in this process.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $signature = json_encode($this->migrateFreshUsing());

        if (self::$migratedSignature !== $signature) {
            RefreshDatabaseState::$migrated = false;
            self::$migratedSignature        = $signature;
        }
    }

    /**
     * Migrate every schema the tenant runtime needs. Called by
     * {@see RefreshDatabase::refreshTestDatabase()} only when a migration is due.
     */
    protected function migrateDatabases(): void
    {
        $this->migrateDefaultConnection();

        $this->migrateSettingsTable();
        $this->migrateLandlord();
    }

    /**
     * Run only tenant-schema migrations on the default (sqlite) connection.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--path'  => 'database/migrations/tenant',
            '--force' => true,
        ];
    }

    /**
     * Spatie's settings table migration lives in {@code database/settings/} and
     * is run by {@see \App\Domains\Tenancy\Services\TenantProvisioningService}
     * via {@code MigrationScope::settings()} in production. For tests we run
     * it explicitly on the default tenant connection so code reading
     * {@code TenantSettings} works.
     */
    private function migrateSettingsTable(): void
    {
        $this->artisan('migrate', [
            '--path'  => 'database/settings',
            '--force' => true,
        ]);
    }

    /**
     * Run landlord migrations on the landlord connection and seed the test tenant.
     * This is needed because RefreshDatabase only migrates the default connection.
     */
    private function migrateLandlord(): void
    {
        DB::connection('landlord')->getSchemaBuilder()->dropAllTables();

        $this->artisan('migrate', [
            '--path'     => 'database/migrations/landlord',
            '--database' => 'landlord',
            '--force'    => true,
        ]);

        DB::connection('landlord')->table('tenants')->insert([
            'id'          => '00000000-0000-0000-0000-000000000001',
            'slug'        => 'main',
            'schema_name' => 'main',
            'status'      => 'active',
            'config'      => '{}',
        ]);
    }

    /**
     * Absolute URL for a panel path.
     *
     * The admin and assistant panels are bound to the tenant host, so a bare
     * path resolves against the base domain and answers 404. Tests that exercise
     * a panel go through here rather than repeating the host.
     */
    protected function panelUrl(string $path): string
    {
        $host = TenantHost::forDefaultTenant();

        if (null === $host) {
            return $path;
        }

        return 'http://' . $host . '/' . ltrim($path, '/');
    }
}
