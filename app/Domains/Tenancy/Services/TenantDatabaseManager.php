<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Exceptions\ConnectionStackEmptyException;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TenantDatabaseManager implements TenantDatabaseManagerInterface
{
    /**
     * @var array<int, array{connection: string, search_path: string|null}>
     */
    private array $stack = [];

    public function createSchema(TenantInterface $tenant): void
    {
        DB::connection('landlord')->statement(
            'CREATE SCHEMA IF NOT EXISTS ' . $this->quoteIdentifier($tenant->getSchemaName())
        );
    }

    public function dropSchema(TenantInterface $tenant): void
    {
        DB::connection('landlord')->statement(
            'DROP SCHEMA IF EXISTS ' . $this->quoteIdentifier($tenant->getSchemaName()) . ' CASCADE'
        );
    }

    public function schemaExists(TenantInterface $tenant): bool
    {
        $result = DB::connection('landlord')->selectOne(
            'SELECT 1 FROM information_schema.schemata WHERE schema_name = ?',
            [$tenant->getSchemaName()]
        );

        return null !== $result;
    }

    public function switchTo(TenantInterface $tenant): void
    {
        $previousConnection = DB::getDefaultConnection();
        $previousSearchPath = null;

        if ('tenant' === $previousConnection) {
            /** @var string|null $configured */
            $configured         = config('database.connections.tenant.search_path');
            $previousSearchPath = $configured;
        }

        $this->stack[] = [
            'connection'  => $previousConnection,
            'search_path' => $previousSearchPath,
        ];

        config(['database.connections.tenant.search_path' => $tenant->getSchemaName()]);
        DB::purge('tenant');
        DB::setDefaultConnection('tenant');
    }

    public function restore(): void
    {
        if (empty($this->stack)) {
            throw ConnectionStackEmptyException::make();
        }

        $previous = array_pop($this->stack);

        if ('tenant' === $previous['connection']) {
            config(['database.connections.tenant.search_path' => $previous['search_path'] ?? 'public']);
            DB::purge('tenant');
        }

        DB::setDefaultConnection($previous['connection']);
    }

    public function runForTenant(TenantInterface $tenant, Closure $callback): mixed
    {
        $this->switchTo($tenant);

        try {
            return $callback();
        } finally {
            $this->restore();
        }
    }

    public function runMigrations(MigrationScope $scope): void
    {
        Artisan::call('migrate', [
            '--path'     => $scope->path,
            '--force'    => true,
            '--realpath' => true,
        ]);
    }

    private function quoteIdentifier(string $name): string
    {
        if ( ! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(
                "Invalid schema name: [{$name}]. Only lowercase alphanumeric and underscores allowed."
            );
        }

        return '"' . $name . '"';
    }
}
