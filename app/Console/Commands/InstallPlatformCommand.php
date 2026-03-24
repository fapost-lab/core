<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class InstallPlatformCommand extends Command
{
    protected $signature = 'platform:install
        {--tenant-slug=main : Slug для первого тенанта}
        {--admin-email= : Email первого администратора}
        {--admin-password= : Пароль первого администратора}
        {--force : Пропустить подтверждения}';

    protected $description = 'Install FAPost Core platform';

    public function __construct(
        private readonly TenantProvisioningService $provisioningService,
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantSwitcher $tenantSwitcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('FAPost Core - Platform Installation');
        $this->newLine();

        $this->components->task('Checking database connection', function (): void {
            DB::connection('landlord')->getPdo();
        });

        $this->components->task('Running landlord migrations', function (): void {
            Artisan::call('migrate', [
                '--path'     => 'database/migrations/landlord',
                '--database' => 'landlord',
                '--force'    => true,
            ]);
        });

        $slug = (string) $this->option('tenant-slug');

        $this->components->task("Provisioning tenant [{$slug}]", function () use ($slug): void {
            $tenant = $this->provisioningService->provision($slug);
            $this->line("  Schema: {$tenant->getSchemaName()}");
        });

        $email    = $this->option('admin-email') ?: $this->ask('Admin email');
        $password = $this->option('admin-password') ?: $this->secret('Admin password');

        $this->components->task('Preparing admin user setup', function () use ($slug, $email): void {
            $tenant = $this->tenantRepository->findBySlug($slug);

            if (null === $tenant) {
                return;
            }

            $this->tenantSwitcher->runForTenant($tenant, function () use ($email): void {
                $this->line("  Admin: {$email} - will be created when Staff Domain is ready");
            });
        });

        $this->components->task('Warming up cache', function (): void {
            Artisan::call('route:cache');
        });

        $this->newLine();
        $this->info('Installation complete!');
        $this->newLine();
        $this->line('Add to your .env:');
        $this->line("  TENANT_SLUG={$slug}");
        $this->newLine();

        return self::SUCCESS;
    }
}
