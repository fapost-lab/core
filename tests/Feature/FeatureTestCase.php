<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base class for feature tests that require tenant context.
 *
 * Runs landlord migrations on the landlord connection and seeds a test tenant
 * so that TenancyMiddleware can resolve it during HTTP tests.
 */
abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Force fresh migration on each test class run since subclasses may use
        // different --path options than the previous test class.
        RefreshDatabaseState::$migrated = false;
        parent::setUp();
        $this->setUpLandlord();
        $this->setUpSettingsTable();
    }

    /**
     * Spatie's settings table migration lives in {@code database/settings/} and
     * is run by {@see \App\Domains\Tenancy\Services\TenantProvisioningService}
     * via {@code MigrationScope::settings()} in production. For tests we run
     * it explicitly on the default tenant connection so code reading
     * {@code TenantSettings} works.
     */
    private function setUpSettingsTable(): void
    {
        $this->artisan('migrate', [
            '--path'  => 'database/settings',
            '--force' => true,
        ]);
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
     * Run landlord migrations on the landlord connection and seed the test tenant.
     * This is needed because RefreshDatabase only migrates the default connection.
     */
    private function setUpLandlord(): void
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
}
