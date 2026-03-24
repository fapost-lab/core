<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\TenantProvisioningException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates the landlord tenant record, PostgreSQL schema, and applies platform tenant migrations.
 */
final readonly class TenantProvisioningService
{
    public function __construct(
        private TenantRepositoryInterface $tenantRepository,
        private TenantDatabaseManagerInterface $databaseManager,
        private TenantSwitcher $tenantSwitcher,
    ) {
    }

    /**
     * Provisions a tenant for the given slug: persist, create schema, migrate tenant platform DDL.
     *
     * Guarantees:
     * - Status is active only after tenant migrations succeed; until then the row stays inactive.
     * - If an error occurs after the tenant row exists, status is set inactive before surfacing
     *   {@see TenantProvisioningException}.
     *
     * Migrations run inside {@see TenantSwitcher::runForTenant()} so the tenant connection and context match.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws TenantProvisioningException on schema conflict or any failure during provisioning.
     */
    public function provision(string $slug, array $config = []): TenantInterface
    {
        $schemaName      = 'tenant_' . Str::slug($slug, '_');
        $temporaryTenant = $this->makeTemporaryTenant($slug, $schemaName);

        if ($this->databaseManager->schemaExists($temporaryTenant)) {
            throw new TenantProvisioningException("Schema {$schemaName} already exists.");
        }

        $tenant = new Tenant([
            'slug'        => $slug,
            'schema_name' => $schemaName,
            'status'      => TenantStatus::Inactive,
            'config'      => $config,
        ]);

        $this->tenantRepository->save($tenant);

        try {
            $this->databaseManager->createSchema($tenant);
            $this->tenantSwitcher->runForTenant($tenant, function (): void {
                $this->databaseManager->runMigrations(MigrationScope::tenant());
            });
        } catch (Throwable $throwable) {
            $tenant->fill(['status' => TenantStatus::Inactive->value]);
            $this->tenantRepository->save($tenant);

            throw new TenantProvisioningException(
                "Failed to provision tenant {$slug}: {$throwable->getMessage()}",
                previous: $throwable,
            );
        }

        $tenant->fill(['status' => TenantStatus::Active->value]);
        $this->tenantRepository->save($tenant);

        return $tenant;
    }

    private function makeTemporaryTenant(string $slug, string $schemaName): TenantInterface
    {
        return new readonly class ($slug, $schemaName) implements TenantInterface {
            public function __construct(
                private string $slug,
                private string $schemaName,
            ) {
            }

            public function getId(): string
            {
                return '';
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getSchemaName(): string
            {
                return $this->schemaName;
            }

            public function isActive(): bool
            {
                return false;
            }

            public function getConfig(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        };
    }
}
