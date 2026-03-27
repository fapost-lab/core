<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs {@see MigrationScope::tenant()} for every active tenant, using {@see TenantSwitcher} only for switching.
 */
final class TenantsMigrateCommand extends Command
{
    protected $signature = 'tenants:migrate';

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

        if ([] === $tenants) {
            $this->components->info('No active tenants found.');

            return self::SUCCESS;
        }

        $count = count($tenants);
        $this->components->info("Running tenant migrations for {$count} active tenant(s).");
        $this->newLine();

        $success     = 0;
        $fail        = 0;
        $failedSlugs = [];

        foreach ($tenants as $tenant) {
            $slug = $tenant->getSlug();
            $this->line("[{$slug}] → migrating …");

            try {
                $this->tenantSwitcher->runForTenant($tenant, function (): void {
                    $this->databaseManager->runMigrations(MigrationScope::tenant());
                });
                $this->line("[{$slug}] → <info>OK</info>");
                $success++;
            } catch (Throwable $throwable) {
                $fail++;
                $failedSlugs[] = $slug;
                $this->line("[{$slug}] → <error>FAILED</error> " . $throwable->getMessage());

                if ($this->output->isVerbose()) {
                    $this->line($throwable->getTraceAsString());
                }
            }

            $this->newLine();
        }

        $this->components->twoColumnDetail('Success', (string) $success);
        $this->components->twoColumnDetail('Failed', (string) $fail);

        if ([] !== $failedSlugs) {
            $this->newLine();
            $this->components->error('Failed tenant slugs: ' . implode(', ', $failedSlugs));
        }

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
