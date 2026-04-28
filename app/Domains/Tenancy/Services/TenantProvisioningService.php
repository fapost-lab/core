<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
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
 * Creates the landlord tenant record, PostgreSQL schema, tenant migrations, and first staff admin.
 */
final readonly class TenantProvisioningService
{
    public function __construct(
        private TenantRepositoryInterface $tenantRepository,
        private TenantDatabaseManagerInterface $databaseManager,
        private TenantSwitcher $tenantSwitcher,
        private AclBootstrapService $aclBootstrapService,
        private ChannelWebhookRegistryInterface $channelWebhookRegistry,
    ) {
    }

    /**
     * Provisions a tenant: persist (inactive), schema, migrations, first admin user, then activate.
     *
     * Guarantees:
     * - Status becomes active only after the first admin user exists in the tenant schema with the admin role.
     * - Migration and user creation run inside {@see TenantSwitcher::runForTenant()} (tenant DB + context).
     * - On failure after the tenant row exists, status is set inactive before
     *   {@see TenantProvisioningException}.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws TenantProvisioningException on schema conflict, missing admin credentials, or any step failure.
     */
    public function provision(
        string $slug,
        string $firstAdminEmail,
        string $firstAdminPassword,
        string $firstAdminName = 'Administrator',
        array $config = [],
    ): TenantInterface {
        if ('' === $firstAdminPassword || '' === mb_trim($firstAdminEmail)) {
            throw new TenantProvisioningException('First admin email and password are required.');
        }

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
            $this->tenantSwitcher->runForTenant($tenant, function () use (
                $firstAdminEmail,
                $firstAdminPassword,
                $firstAdminName,
                $tenant,
            ): void {
                $this->databaseManager->runMigrations(MigrationScope::settings());
                $this->databaseManager->runMigrations(MigrationScope::tenant());
                $this->aclBootstrapService->bootstrap();
                $this->createFirstTenantAdminUser($firstAdminEmail, $firstAdminPassword, $firstAdminName);
                $this->channelWebhookRegistry->warmup($tenant);
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

    /**
     * @internal Invoked only inside {@see TenantSwitcher::runForTenant()} after tenant migrations.
     */
    private function createFirstTenantAdminUser(string $email, string $password, string $name): void
    {
        $user = User::query()->create([
            'name'              => $name,
            'email'             => $email,
            'password'          => $password,
            'email_verified_at' => now(),
            'status'            => UserStatus::Active,
        ]);

        $user->assignRole('admin');
    }
}
