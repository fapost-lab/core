<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use RuntimeException;

final class MigrateSmartCommand extends Command
{
    protected $signature = 'migrate:smart
        {--landlord : Check pending landlord migrations only}
        {--tenant : Check pending tenant migrations for the default local tenant only}
        {--all : Check both landlord and tenant migrations}';

    protected $description = 'Show pending landlord and default local tenant migrations without running them';

    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly Migrator $migrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        ['landlord' => $shouldCheckLandlord, 'tenant' => $shouldCheckTenant] = $this->resolveChecks();

        $exitCode = self::SUCCESS;

        if ($shouldCheckLandlord) {
            $this->line('Landlord pending: ' . $this->countPendingLandlordMigrations());
        }

        if ($shouldCheckTenant) {
            try {
                $this->line('Tenant pending: ' . $this->countPendingTenantMigrations());
            } catch (RuntimeException $exception) {
                $this->components->error($exception->getMessage());
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * @return array{landlord: bool, tenant: bool}
     */
    private function resolveChecks(): array
    {
        $checkAll      = (bool)$this->option('all');
        $checkLandlord = $checkAll || (bool)$this->option('landlord');
        $checkTenant   = $checkAll || (bool)$this->option('tenant');

        if (! $checkLandlord && ! $checkTenant) {
            return [
                'landlord' => true,
                'tenant'   => true,
            ];
        }

        return [
            'landlord' => $checkLandlord,
            'tenant'   => $checkTenant,
        ];
    }

    private function countPendingLandlordMigrations(): int
    {
        return $this->countPendingMigrationsForConnection(
            connection: 'landlord',
            paths: [database_path('migrations/landlord')],
        );
    }

    /**
     * @param  list<string>  $paths
     */
    private function countPendingMigrationsForConnection(string $connection, array $paths): int
    {
        return $this->migrator->usingConnection($connection, function () use ($paths): int {
            $migrationFiles = $this->migrator->getMigrationFiles($paths);

            if (! $this->migrator->repositoryExists()) {
                return count($migrationFiles);
            }

            $ranMigrations = $this->migrator->getRepository()->getRan();

            return count(
                array_filter(
                    $migrationFiles,
                    fn (string $migrationFile): bool => ! in_array(
                        $this->migrator->getMigrationName($migrationFile),
                        $ranMigrations,
                        true,
                    ),
                )
            );
        });
    }

    private function countPendingTenantMigrations(): int
    {
        $defaultTenantSlug = (string)config('tenancy.default_tenant_slug', 'app');
        $tenant            = $this->tenantRepository->findBySlug($defaultTenantSlug);

        if (null === $tenant) {
            throw new RuntimeException(
                "Default local tenant [{$defaultTenantSlug}] was not found. Run platform:install or create that tenant before checking tenant migrations.",
            );
        }

        return $this->tenantSwitcher->runForTenant($tenant, fn (): int => $this->countPendingMigrationsForConnection(
            connection: (string)config('tenancy.tenant_connection', 'tenant'),
            paths: [MigrationScope::tenant()->path],
        ));
    }
}
