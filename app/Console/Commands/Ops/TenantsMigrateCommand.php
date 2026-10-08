<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs {@see MigrationScope::tenant()} for every active tenant. Keeps tenant schema current;
 * later operational commands (e.g. seed ACL, sync webhooks) can be run separately after migrate.
 */
final class TenantsMigrateCommand extends Command
{
    /** @var string */
    protected $signature = 'ops:tenants-migrate';

    /** @var string */
    protected $description = 'Run database/migrations/tenant for all active tenants';

    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly TenantDatabaseManagerInterface $databaseManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenants = $this->tenantRepository->findAllActive();

        $success = 0;
        $fail    = 0;

        foreach ($tenants as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $this->tenantSwitcher->runForTenant($tenant, function (): void {
                    $this->databaseManager->runMigrations(MigrationScope::tenant());
                });
                $this->line("✔ {$slug}");
                $success++;
            } catch (Throwable $throwable) {
                $this->line("✘ {$slug}: {$throwable->getMessage()}");
                $fail++;
            }
        }

        $this->newLine();
        $this->line("Migrated: {$success} successful, {$fail} failed");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
