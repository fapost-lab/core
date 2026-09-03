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
 *
 * On PostgreSQL the tenant tables live in a schema of their own, as they do in
 * production, and the default connection is pointed at that schema for every
 * test. The seeded tenant names the same schema, so a tenant switch during a
 * test lands on the migrated tables. The landlord connection may target the
 * same database (its tables stay in `public`) or a separate one.
 */
abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase {
        migrateDatabases as private migrateDefaultConnection;
    }

    /**
     * Schema the seeded tenant owns. Its slug matches TENANT_SLUG in phpunit.xml.
     */
    private const string TENANT_SCHEMA = 'main';

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
        $this->pointDefaultConnectionAtTenantSchema();

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
        $this->createTenantSchema();
        $this->migrateDefaultConnection();

        $this->migrateSettingsTable();
        $this->migrateLandlord();
    }

    /**
     * Run only tenant-schema migrations on the default connection.
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

        return 'http://' . $host . '/' . mb_ltrim($path, '/');
    }

    /**
     * The application, and with it the config, is rebuilt for every test, so
     * the search_path is set again each time, before the transaction starts.
     * A connection opened during boot would still carry the old search_path;
     * purging it is safe here because nothing has been written on it yet.
     * SQLite has no schemas, and purging an :memory: database destroys it.
     */
    private function pointDefaultConnectionAtTenantSchema(): void
    {
        if (! $this->usingPostgres()) {
            return;
        }

        $default = (string) config('database.default');

        config(["database.connections.{$default}.search_path" => self::TENANT_SCHEMA]);
        DB::purge($default);
    }

    /**
     * `migrate:fresh` drops every table in the search_path but does not create
     * the schema the search_path names.
     */
    private function createTenantSchema(): void
    {
        if (! $this->usingPostgres()) {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS "' . self::TENANT_SCHEMA . '"');
    }

    private function usingPostgres(): bool
    {
        $default = (string) config('database.default');

        return 'pgsql' === config("database.connections.{$default}.driver");
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
            'schema_name' => self::TENANT_SCHEMA,
            'status'      => 'active',
            'config'      => '{}',
        ]);
    }
}
