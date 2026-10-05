<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Database\TenantDatabaseManager;
use App\Domains\Tenancy\Exceptions\ConnectionStackEmptyException;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class TenantDatabaseManagerTest extends TestCase
{
    public function test_switch_and_restore_changes_tenant_schema(): void
    {
        $manager    = $this->app->make(TenantDatabaseManager::class);
        $tenant     = $this->makeTenant('acme');
        $tenantConn = (string) config('tenancy.tenant_connection');

        $beforeSearchPath = (string) config("database.connections.{$tenantConn}.search_path");
        $beforeDefault    = DB::getDefaultConnection();

        $manager->switchTo($tenant);

        // After switchTo: search_path must point to tenant schema
        $this->assertEquals(
            $tenant->getSchemaName(),
            (string) config("database.connections.{$tenantConn}.search_path"),
        );
        // Default connection must be the tenant connection
        $this->assertEquals($tenantConn, DB::getDefaultConnection());

        $manager->restore();

        // After restore: default connection and search_path are back
        $this->assertEquals($beforeDefault, DB::getDefaultConnection());
        $this->assertEquals(
            $beforeSearchPath,
            (string) config("database.connections.{$tenantConn}.search_path"),
        );
    }

    /**
     * A switch must not reconnect: a transaction already open on the tenant
     * connection (a queue job's, the test harness's) would be lost with the PDO.
     */
    public function test_switch_and_restore_keep_the_open_connection(): void
    {
        $manager    = $this->app->make(TenantDatabaseManager::class);
        $tenant     = $this->makeTenant('acme');
        $tenantConn = (string) config('tenancy.tenant_connection');

        $pdo = DB::connection($tenantConn)->getPdo();

        $manager->switchTo($tenant);
        $this->assertSame($pdo, DB::connection($tenantConn)->getPdo());

        $manager->restore();
        $this->assertSame($pdo, DB::connection($tenantConn)->getPdo());
    }

    public function test_switch_moves_an_open_postgres_session_to_the_tenant_schema(): void
    {
        $tenantConn = (string) config('tenancy.tenant_connection');
        $connection = DB::connection($tenantConn);

        if ('pgsql' !== $connection->getDriverName()) {
            $this->markTestSkipped('search_path is a PostgreSQL concept.');
        }

        $manager = $this->app->make(TenantDatabaseManager::class);
        $tenant  = $this->makeTenant('acme');

        $before = $this->currentSearchPath($tenantConn);
        $this->assertNotSame($tenant->getSchemaName(), $before);

        $manager->switchTo($tenant);

        // Both the session and what the schema builder believes must move.
        $this->assertSame($tenant->getSchemaName(), $this->currentSearchPath($tenantConn));
        $this->assertSame($tenant->getSchemaName(), Schema::connection($tenantConn)->getCurrentSchemaName());

        $manager->restore();

        $this->assertSame($before, $this->currentSearchPath($tenantConn));
        $this->assertSame($before, Schema::connection($tenantConn)->getCurrentSchemaName());
    }

    /**
     * TenantSwitcher restores from a finally block. Once a statement has failed
     * inside a transaction PostgreSQL rejects every further statement, SET
     * included; the restore must not add a second error on top of the first,
     * and the rollback that follows must leave the session on the previous
     * search_path.
     */
    public function test_restore_inside_an_aborted_postgres_transaction_does_not_throw(): void
    {
        $tenantConn = (string) config('tenancy.tenant_connection');
        $connection = DB::connection($tenantConn);

        if ('pgsql' !== $connection->getDriverName()) {
            $this->markTestSkipped('Aborted transactions are a PostgreSQL concept.');
        }

        $manager = $this->app->make(TenantDatabaseManager::class);
        $tenant  = $this->makeTenant('acme');
        $before  = $this->currentSearchPath($tenantConn);

        $connection->beginTransaction();

        try {
            $manager->switchTo($tenant);

            try {
                $connection->select('select 1 / 0');
            } catch (QueryException) {
                // The transaction is now aborted.
            }

            $manager->restore();
        } finally {
            $connection->rollBack();
        }

        $this->assertSame($before, $this->currentSearchPath($tenantConn));
        $this->assertSame($before, Schema::connection($tenantConn)->getCurrentSchemaName());
    }

    /**
     * A connection that has been resolved but not opened yet captured its
     * config at resolve time; the switch must still reach the session once it
     * does open.
     */
    public function test_switch_on_an_unopened_postgres_connection_applies_when_it_opens(): void
    {
        $tenantConn = (string) config('tenancy.tenant_connection');

        if ('pgsql' !== (string) config("database.connections.{$tenantConn}.driver")) {
            $this->markTestSkipped('search_path is a PostgreSQL concept.');
        }

        DB::purge($tenantConn);
        DB::connection($tenantConn);

        $manager = $this->app->make(TenantDatabaseManager::class);
        $tenant  = $this->makeTenant('acme');

        $manager->switchTo($tenant);

        $this->assertSame($tenant->getSchemaName(), $this->currentSearchPath($tenantConn));

        $manager->restore();
    }

    /**
     * PostgreSQL truncates a longer name on CREATE, so the existence check would compare a
     * name the server never stored and miss the schema it aliases. Both refuse before SQL.
     */
    public function test_schema_names_past_the_identifier_limit_never_reach_the_database(): void
    {
        $manager = new TenantDatabaseManager();
        $tenant  = $this->makeTenant(str_repeat('a', TenantDatabaseManager::MAX_IDENTIFIER_BYTES));

        DB::shouldReceive('connection')->never();

        foreach (['schemaExists', 'createSchema'] as $method) {
            try {
                $manager->{$method}($tenant);
                $this->fail("{$method} accepted a schema name longer than the identifier limit.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('identifier limit', $e->getMessage());
            }
        }
    }

    public function test_restore_throws_on_empty_stack(): void
    {
        $manager = new TenantDatabaseManager();

        $this->expectException(ConnectionStackEmptyException::class);
        $manager->restore();
    }

    public function test_run_for_tenant_restores_on_exception(): void
    {
        $switcher = $this->app->make(TenantSwitcher::class);
        $tenant   = $this->makeTenant('acme');
        $before   = DB::getDefaultConnection();

        try {
            $switcher->runForTenant($tenant, function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertEquals($before, DB::getDefaultConnection());
    }

    public function test_nested_run_for_tenant_restores_correctly(): void
    {
        $switcher = $this->app->make(TenantSwitcher::class);
        $tenantA  = $this->makeTenant('alpha');
        $tenantB  = $this->makeTenant('beta');
        $before   = DB::getDefaultConnection();

        $tenantConn = (string) config('tenancy.tenant_connection');

        $switcher->runForTenant($tenantA, function () use ($switcher, $tenantB, $tenantConn): void {
            $afterA      = DB::getDefaultConnection();
            $searchPathA = (string) config("database.connections.{$tenantConn}.search_path");

            $switcher->runForTenant($tenantB, function () use ($afterA, $searchPathA, $tenantConn): void {
                $this->assertEquals($afterA, DB::getDefaultConnection());
                $this->assertNotEquals($searchPathA, (string) config("database.connections.{$tenantConn}.search_path"));
            });

            $this->assertEquals($afterA, DB::getDefaultConnection());
            $this->assertEquals($searchPathA, (string) config("database.connections.{$tenantConn}.search_path"));
        });

        $this->assertEquals($before, DB::getDefaultConnection());
    }

    public function test_migration_scope_labels(): void
    {
        $this->assertEquals('tenant', MigrationScope::tenant()->label);
        $this->assertEquals('features', MigrationScope::features()->label);
        $this->assertEquals('module:hr', MigrationScope::module('hr')->label);
    }

    public function test_migration_scope_paths(): void
    {
        $this->assertStringEndsWith(
            'migrations/tenant',
            MigrationScope::tenant()->path,
        );
        $this->assertStringEndsWith(
            'migrations/modules/hr',
            MigrationScope::module('hr')->path,
        );
    }

    /**
     * What the PostgreSQL session itself resolves unqualified names against.
     */
    private function currentSearchPath(string $connection): string
    {
        /** @var object{search_path: string} $row */
        $row = DB::connection($connection)->selectOne('SHOW search_path');

        return mb_trim($row->search_path, '"');
    }

    private function makeTenant(string $slug): TenantInterface
    {
        return new class ($slug) implements TenantInterface {
            public function __construct(private string $slug)
            {
            }

            public function getId(): string
            {
                return 'test-id';
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getSchemaName(): string
            {
                return 'tenant_' . $this->slug;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function getConfig(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        };
    }
}
