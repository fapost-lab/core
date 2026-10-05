<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;

/**
 * Runs a feature test with `TENANCY_RESOLUTION=host` and a second tenant, `second`.
 *
 * Some config is read once while the application boots (the panel domain, the routes), so the
 * mode is set as a real process variable and the application is rebuilt, as
 * {@see \Tests\Unit\Domains\Tenancy\TenancyResolutionModeTest} does; setting config after
 * boot would be too late. The class calls {@see provisionSecondTenant()} from `setUp()` and
 * {@see restoreTenancyResolution()} from `tearDown()`; it must extend
 * {@see \Tests\Feature\FeatureTestCase}.
 */
trait RunsInHostMode
{
    private const string SECOND_SCHEMA = 'second_schema';

    /**
     * @var array{env: mixed, server: mixed, getenv: string|false}|null
     */
    private ?array $previousResolution = null;

    protected function refreshApplication(): void
    {
        $this->previousResolution ??= [
            'env'    => $_ENV['TENANCY_RESOLUTION'] ?? null,
            'server' => $_SERVER['TENANCY_RESOLUTION'] ?? null,
            'getenv' => getenv('TENANCY_RESOLUTION'),
        ];

        // Forget the value the loader wrote on a previous boot, so the one below counts
        // as externally defined and wins over .env.
        Env::getRepository()->clear('TENANCY_RESOLUTION');
        $_ENV['TENANCY_RESOLUTION'] = $_SERVER['TENANCY_RESOLUTION'] = 'host';
        putenv('TENANCY_RESOLUTION=host');

        parent::refreshApplication();
    }

    protected function restoreTenancyResolution(): void
    {
        $previous = $this->previousResolution;

        Env::getRepository()->clear('TENANCY_RESOLUTION');
        $this->restoreVariable($_ENV, $previous['env'] ?? null);
        $this->restoreVariable($_SERVER, $previous['server'] ?? null);
        putenv(false === ($previous['getenv'] ?? false) ? 'TENANCY_RESOLUTION' : 'TENANCY_RESOLUTION=' . $previous['getenv']);
    }

    protected function provisionSecondTenant(): void
    {
        if ($this->onPostgres()) {
            $this->createSecondSchema();
        }

        DB::connection('landlord')->table('tenants')->insert([
            'id'          => '00000000-0000-0000-0000-000000000002',
            'slug'        => 'second',
            'schema_name' => self::SECOND_SCHEMA,
            'status'      => 'active',
            'config'      => '{}',
        ]);
    }

    protected function onPostgres(): bool
    {
        return 'pgsql' === config('database.connections.' . config('tenancy.tenant_connection') . '.driver');
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function restoreVariable(array &$bag, mixed $value): void
    {
        if (null === $value) {
            unset($bag['TENANCY_RESOLUTION']);

            return;
        }

        $bag['TENANCY_RESOLUTION'] = $value;
    }

    /**
     * A schema of its own with the tenant tables, so users really differ between the tenants.
     *
     * TenantProvisioningService is not used on purpose: in tests the landlord is a separate
     * database (CI uses `fapost_landlord_test`) and FeatureTestCase wraps the `landlord`
     * connection in a transaction, so a schema the service creates through landlord is
     * invisible to the tenant connection (the same goes for createSchema()). The schema is created on the tenant connection
     * and entered through the production switcher, which keeps connection config and
     * session in sync.
     */
    private function createSecondSchema(): void
    {
        $tenant   = new Tenant(['slug' => 'second', 'schema_name' => self::SECOND_SCHEMA]);
        $database = $this->app->make(TenantDatabaseManagerInterface::class);

        DB::connection((string) config('tenancy.tenant_connection'))
            ->statement('CREATE SCHEMA ' . self::SECOND_SCHEMA);
        $database->switchTo($tenant);

        try {
            foreach (['database/settings', 'database/migrations/tenant'] as $path) {
                $this->artisan('migrate', ['--path' => $path, '--force' => true])->assertExitCode(0);
            }

            $this->seed(TenantAclSeeder::class);
        } finally {
            $database->restore();
        }
    }
}
