<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class InstallPlatformCommand extends Command
{
    protected $signature = 'platform:install
        {--tenant-slug=app : Slug for the first tenant}
        {--admin-email= : Email for the first admin}
        {--admin-password= : Password for the first admin}';

    protected $description = 'Install FAPOST Core platform';

    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantProvisioningService $provisioningService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('FAPOST Core - Platform Installation');
        $this->newLine();

        $this->components->task('Checking database connection', function (): void {
            DB::connection('landlord')->getPdo();
        });

        if ($this->tenantRepository->existsAny()) {
            $this->components->error('Platform is already installed. Only one tenant is allowed in self-hosted mode.');

            return self::FAILURE;
        }

        $migrateExitCode = 0;
        $this->components->task('Running landlord migrations', function () use (&$migrateExitCode): void {
            $migrateExitCode = Artisan::call('migrate', [
                '--path'     => 'database/migrations/landlord',
                '--database' => 'landlord',
                '--force'    => true,
            ]);
        });

        if (self::SUCCESS !== $migrateExitCode) {
            $this->components->error('Landlord migrations failed. Installation aborted.');

            return self::FAILURE;
        }

        $slug     = (string)$this->option('tenant-slug');
        $email    = $this->option('admin-email') ?: $this->ask('Admin email');
        $password = $this->option('admin-password') ?: $this->secret('Admin password');

        $this->components->task(
            "Provisioning tenant [{$slug}] and first admin",
            function () use ($slug, $email, $password): void {
                $tenant = $this->provisioningService->provision(
                    slug: $slug,
                    firstAdminEmail: $email,
                    firstAdminPassword: $password,
                );
                $this->line("  Schema: {$tenant->getSchemaName()}");
                $this->line("  Admin: {$email}");
                $this->line('  Role: admin (ACL bootstrap) assigned to the first admin.');
            }
        );

        $this->newLine();
        $this->info('Installation complete!');
        $this->newLine();
        $this->line('Add to your .env:');
        $this->line("  TENANT_SLUG={$slug}");
        $this->newLine();

        return self::SUCCESS;
    }
}
