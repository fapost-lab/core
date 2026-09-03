<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Database;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Exceptions\ConnectionStackEmptyException;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tenant database manager implementation.
 *
 * Responsible for creating/dropping tenant schemas, switching default DB connection to a tenant schema,
 * and running tenant-scoped migrations.
 *
 * @see TenantDatabaseManagerInterface
 */
final class TenantDatabaseManager implements TenantDatabaseManagerInterface
{
    /**
     * @var array<int, array{connection: string, search_path: array<int, string>|string|null}>
     */
    private array $stack = [];

    /**
     * Create schema for the given tenant in the landlord database.
     */
    public function createSchema(TenantInterface $tenant): void
    {
        DB::connection('landlord')->statement(
            'CREATE SCHEMA IF NOT EXISTS ' . $this->quoteIdentifier($tenant->getSchemaName())
        );
    }

    /**
     * Drop schema for the given tenant (with CASCADE).
     */
    public function dropSchema(TenantInterface $tenant): void
    {
        DB::connection('landlord')->statement(
            'DROP SCHEMA IF EXISTS ' . $this->quoteIdentifier($tenant->getSchemaName()) . ' CASCADE'
        );
    }

    /**
     * Check if tenant schema already exists.
     */
    public function schemaExists(TenantInterface $tenant): bool
    {
        $result = DB::connection('landlord')->selectOne(
            'SELECT 1 FROM information_schema.schemata WHERE schema_name = ?',
            [$tenant->getSchemaName()]
        );

        return null !== $result;
    }

    /**
     * Switch default DB connection to the tenant schema, pushing previous connection onto a stack.
     */
    public function switchTo(TenantInterface $tenant): void
    {
        $tenantConnection   = (string)config('tenancy.tenant_connection', 'tenant');
        $previousConnection = DB::getDefaultConnection();
        $previousSearchPath = null;

        if ($previousConnection === $tenantConnection) {
            /** @var array<int, string>|string|null $configured */
            $configured         = config("database.connections.{$tenantConnection}.search_path");
            $previousSearchPath = $configured;
        }

        $this->stack[] = [
            'connection'  => $previousConnection,
            'search_path' => $previousSearchPath,
        ];

        $this->applySearchPath($tenantConnection, $tenant->getSchemaName());

        DB::setDefaultConnection($tenantConnection);
    }

    /**
     * Restore previous DB connection/search_path from the stack.
     *
     * @throws ConnectionStackEmptyException when called without a previous switch.
     */
    public function restore(): void
    {
        if (empty($this->stack)) {
            throw ConnectionStackEmptyException::make();
        }

        $tenantConnection = (string)config('tenancy.tenant_connection', 'tenant');
        $previous         = array_pop($this->stack);

        if ($previous['connection'] === $tenantConnection) {
            $this->applySearchPath($tenantConnection, $previous['search_path']);
        }

        DB::setDefaultConnection($previous['connection']);
    }

    /**
     * Run migrations for the given migration scope (tenant/platform/etc.).
     */
    public function runMigrations(MigrationScope $scope): void
    {
        Artisan::call('migrate', [
            '--path'     => $scope->path,
            '--force'    => true,
            '--realpath' => true,
        ]);
    }

    /**
     * Point the tenant connection at a schema.
     *
     * The config is what a connection opened later reads. A connection that is
     * already open is moved in place when it can be, so a transaction that is
     * running on it (a queue job's, a test's) survives the switch; any other
     * open connection is purged and re-created with the new config on next use.
     * SQLite has no schemas, and purging an :memory: database destroys it.
     *
     * @param  array<int, string>|string|null  $searchPath
     */
    private function applySearchPath(string $connectionName, array|string|null $searchPath): void
    {
        config(["database.connections.{$connectionName}.search_path" => $searchPath]);

        if ('sqlite' === $this->connectionDriver($connectionName)) {
            return;
        }

        $connection = DB::getConnections()[$connectionName] ?? null;

        if ($connection instanceof TenantPostgresConnection) {
            $connection->useSearchPath($searchPath);

            return;
        }

        if (null !== $connection) {
            DB::purge($connectionName);
        }
    }

    private function quoteIdentifier(string $name): string
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(
                "Invalid schema name: [{$name}]. Only lowercase alphanumeric and underscores allowed."
            );
        }

        return '"' . $name . '"';
    }

    private function connectionDriver(string $connection): string
    {
        return (string)config("database.connections.{$connection}.driver", '');
    }
}
