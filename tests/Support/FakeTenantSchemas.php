<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Closure;

/**
 * A tenant database manager that creates no schema: it remembers names, so tests can say which
 * schemas "exist" and make a given operation throw. Switching is a no-op, as in the other
 * provisioning tests: the first admin lands on the tenant-migrated default connection.
 */
final class FakeTenantSchemas implements TenantDatabaseManagerInterface
{
    /** @var list<string> */
    public array $schemas = [];

    /** @var list<string> */
    public array $created = [];

    /** @var list<string> labels of the migration scopes that ran, in order */
    public array $migrated = [];

    /** @var array<string, Closure(): void> */
    private array $failures = [];

    public function failOn(string $operation, Closure $failure): void
    {
        $this->failures[$operation] = $failure;
    }

    public function stopFailing(string $operation): void
    {
        unset($this->failures[$operation]);
    }

    public function createSchema(TenantInterface $tenant): void
    {
        $this->maybeFail('createSchema');
        $this->created[] = $tenant->getSchemaName();
        $this->schemas[] = $tenant->getSchemaName();
        $this->schemas   = array_values(array_unique($this->schemas));
    }

    public function dropSchema(TenantInterface $tenant): void
    {
        $this->schemas = array_values(array_diff($this->schemas, [$tenant->getSchemaName()]));
    }

    public function schemaExists(TenantInterface $tenant): bool
    {
        return in_array($tenant->getSchemaName(), $this->schemas, true);
    }

    public function switchTo(TenantInterface $tenant): void
    {
    }

    public function restore(): void
    {
    }

    public function runMigrations(MigrationScope $scope): void
    {
        $this->maybeFail('migrate_' . $scope->label);
        $this->migrated[] = $scope->label;
    }

    private function maybeFail(string $operation): void
    {
        if (isset($this->failures[$operation])) {
            ($this->failures[$operation])();
        }
    }
}
