<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Installer\InstallStep;
use App\Console\Installer\Steps\ApplicationStep;
use App\Console\Installer\Steps\DatabaseStep;
use App\Console\Installer\Steps\RedisStep;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Webhook\Deployment\EnvFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

use function Laravel\Prompts\confirm;

use Throwable;

/**
 * Guided installation: configure connections, apply migrations, create the first
 * tenant, and optionally set up the webhook gateway.
 *
 * Deliberately a wizard rather than a script. Each step verifies its answers
 * before writing them, so a wrong host is a question repeated rather than a
 * stack trace two minutes later — and re-running skips whatever is already
 * configured and working, which makes it safe to use for finishing a half-done
 * install.
 *
 * It never touches the host outside the project: no packages, no services, no
 * files under /etc. Those steps are printed for an operator to run.
 */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'install
        {--skip-gateway : Do not offer to set up the webhook gateway}
        {--env-path= : Environment file to configure (default: .env in the project root)}';

    /** @var string */
    protected $description = 'Configure and install FaPost Core step by step';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->components->info('FaPost Core installation');

        $path = (string) ($this->option('env-path') ?: base_path('.env'));
        $env  = new EnvFile($path);

        if (! $env->exists()) {
            $this->components->error("No environment file at {$path}. Run: cp .env.example .env");

            return self::FAILURE;
        }

        foreach ($this->steps() as $step) {
            if (! $this->runStep($step, $env)) {
                $this->components->error("Installation stopped at: {$step->title()}");

                return self::FAILURE;
            }
        }

        // Settings were written to .env after this process booted, so its own
        // configuration is stale; everything below reads the new values.
        Artisan::call('config:clear');

        if (! $this->migrate()) {
            return self::FAILURE;
        }

        if (! $this->provisionTenant()) {
            return self::FAILURE;
        }

        $this->offerGateway();
        $this->summarize();

        return self::SUCCESS;
    }

    /**
     * @return list<InstallStep>
     */
    private function steps(): array
    {
        return [
            new ApplicationStep(),
            new DatabaseStep(),
            new RedisStep(),
        ];
    }

    private function runStep(InstallStep $step, EnvFile $env): bool
    {
        $this->newLine();
        $this->components->info($step->title());

        if (! $step->isPending($env)) {
            $this->components->twoColumnDetail($step->title(), 'already configured');

            return true;
        }

        return $step->run($this, $env);
    }

    private function migrate(): bool
    {
        $this->newLine();
        $this->components->info('Database schema');

        $exitCode = 0;

        $this->components->task('Applying platform migrations', function () use (&$exitCode): void {
            $exitCode = Artisan::call('migrate', [
                '--database' => 'landlord',
                '--force'    => true,
            ]);
        });

        if (self::SUCCESS !== $exitCode) {
            $this->components->error('Platform migrations failed:');
            $this->line(Artisan::output());

            return false;
        }

        return true;
    }

    /**
     * Hand the first tenant over to the existing provisioning command.
     *
     * Reused rather than reimplemented: it already creates the schema, runs
     * tenant migrations, bootstraps the ACL and creates the administrator, and a
     * second copy of that sequence would be one more thing to keep in step.
     */
    private function provisionTenant(): bool
    {
        $this->newLine();
        $this->components->info('First tenant');

        if ($this->tenants->existsAny()) {
            $this->components->twoColumnDetail('Tenant', 'already provisioned');

            return true;
        }

        try {
            return self::SUCCESS === Artisan::call('platform:install', [], $this->output);
        } catch (Throwable $throwable) {
            $this->components->error('Tenant provisioning failed: ' . $throwable->getMessage());

            return false;
        }
    }

    private function offerGateway(): void
    {
        if ($this->option('skip-gateway')) {
            return;
        }

        $this->newLine();
        $this->components->info('Webhook ingress');
        $this->line('  The application handles webhooks itself. An optional Go gateway can');
        $this->line('  take that load off PHP once webhook volume grows.');
        $this->newLine();

        if (confirm('Set up the webhook gateway now?', default: false)) {
            Artisan::call('gateway:install', [], $this->output);

            return;
        }

        $this->line('  You can set it up later with: php artisan gateway:install');
    }

    private function summarize(): void
    {
        $this->newLine();
        $this->components->info('Installed');
        $this->newLine();

        $this->line('  Start the background services — without them nothing is processed:');
        $this->line('      php artisan horizon');
        $this->line('      php artisan schedule:run   (every minute, via cron or a systemd timer)');
        $this->newLine();
        $this->line('  See docs/deployment/services.md for supervisor unit files.');
        $this->newLine();
        $this->line('  Verify with:');
        $this->line('      php artisan about');
        $this->line('      php artisan horizon:status');
        $this->newLine();
    }
}
