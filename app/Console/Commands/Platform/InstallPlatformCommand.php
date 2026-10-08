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
    /** @var string */
    protected $signature = 'platform:install
        {--tenant-slug=app : Slug for the first tenant}
        {--admin-email= : Email for the first admin}
        {--admin-password= : Password for the first admin}
        {--admin-password-file= : Read the admin password from a file, or from standard input when given as -}';

    /** @var string */
    protected $description = 'Install FaPost Core platform';

    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly TenantProvisioningService $provisioningService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('FaPost Core - Platform Installation');
        $this->newLine();

        $this->components->task('Checking database connection', function (): void {
            DB::connection('landlord')->getPdo();
        });

        if ($this->tenantRepository->existsAny()) {
            $this->components->error('Platform is already installed. Only one tenant is allowed in self-hosted mode.');

            return self::FAILURE;
        }

        // Before any DDL: an unreadable password file is a typo on the command
        // line, and finding it out afterwards leaves a migrated landlord schema
        // with no tenant in it.
        $slug     = (string)$this->option('tenant-slug');
        $email    = $this->option('admin-email') ?: $this->ask('Admin email');
        $password = $this->resolveAdminPassword();

        if (null === $password) {
            return self::FAILURE;
        }

        $migrateExitCode = 0;
        $this->components->task('Running platform migrations', function () use (&$migrateExitCode): void {
            $migrateExitCode = Artisan::call('migrate', [
                '--database' => 'landlord',
                '--force'    => true,
            ]);
        });

        if (self::SUCCESS !== $migrateExitCode) {
            $this->components->error('Platform migrations failed. Installation aborted.');

            return self::FAILURE;
        }

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

    /**
     * The admin password, or null when it could not be read — in which case the
     * reason has already been reported.
     *
     * An unattended installer needs to supply this without a prompt, and
     * --admin-password puts it in the process list of every user on the host for
     * as long as the command runs. Reading it from a file, or from standard
     * input as `-`, keeps it out of argv entirely:
     *
     *     printf '%s' "$password" | php artisan platform:install \
     *         --admin-email=… --admin-password-file=-
     */
    private function resolveAdminPassword(): ?string
    {
        $path = (string)$this->option('admin-password-file');

        if ('' === $path) {
            return (string)($this->option('admin-password') ?: $this->secret('Admin password'));
        }

        $contents = @file_get_contents('-' === $path ? 'php://stdin' : $path);

        if (false === $contents) {
            $this->components->error("Could not read the admin password from [{$path}].");

            return null;
        }

        // Only the line ending goes: a password may legitimately start or end
        // with a space, and trimming it would leave an account whose password
        // is not the one that was written.
        $password = mb_rtrim($contents, "\r\n");

        if ('' === $password) {
            $this->components->error("The admin password read from [{$path}] is empty.");

            return null;
        }

        return $password;
    }
}
