<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\TenantProvisioningException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use Illuminate\Support\Str;

final class TenantProvisioningService
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantDatabaseManagerInterface $databaseManager,
    )
    {
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws TenantProvisioningException
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
            'status'      => TenantStatus::Active,
            'config'      => $config,
        ]);

        $this->tenantRepository->save($tenant);

        try {
            $this->databaseManager->createSchema($tenant);
            $this->databaseManager->runMigrations($tenant);
        } catch (\Throwable $throwable) {
            $tenant->fill(['status' => TenantStatus::Inactive->value]);
            $this->tenantRepository->save($tenant);

            throw new TenantProvisioningException(
                "Failed to provision tenant {$slug}: {$throwable->getMessage()}",
                previous: $throwable,
            );
        }

        return $tenant;
    }

    private function makeTemporaryTenant(string $slug, string $schemaName): TenantInterface
    {
        return new readonly class($slug, $schemaName) implements TenantInterface {
            public function __construct(
                private string $slug,
                private string $schemaName,
            )
            {
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
