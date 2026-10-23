<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use Fapost\Foundation\Tenancy\Contracts\TenantOnboardingInterface;
use Fapost\Foundation\Tenancy\Exceptions\TenantOnboardingException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Puts a tenant back into the first-run setup wizard. The staff of the tenant are held in the wizard until its
 * required steps are done again, so the command asks first; `--force` skips the question for scripts.
 *
 * Steps the tenant's data already satisfies (an assistant exists) stay done; confirmations (the content languages)
 * are asked again, and a base language locked by a flow is shown read-only.
 */
final class TenantsOnboardingRestartCommand extends Command
{
    /** @var string */
    protected $signature = 'tenants:onboarding:restart
        {tenant : Slug or id of the tenant}
        {--force : Do not ask for confirmation}';

    /** @var string */
    protected $description = 'Put an active tenant back into the first-run setup wizard';

    public function handle(TenantRepositoryInterface $tenants, TenantOnboardingInterface $onboarding): int
    {
        $reference = (string) $this->argument('tenant');
        $tenant    = $tenants->findBySlug($reference) ?? (Str::isUuid($reference) ? $tenants->findById(mb_strtolower($reference)) : null);

        if (! $tenant instanceof TenantInterface) {
            $this->components->error(sprintf('No tenant "%s".', $reference));

            return self::FAILURE;
        }

        if (! $tenant->isActive()) {
            $this->components->error(sprintf('Tenant "%s" is not active.', $tenant->getSlug()));

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->components->confirm(sprintf('The staff of "%s" will be held in the setup wizard until they finish its required steps. Restart it?', $tenant->getSlug()))) {
            $this->components->warn('Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $onboarding->restart($tenant->getId());
        } catch (TenantOnboardingException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('The setup wizard of "%s" is pending again.', $tenant->getSlug()));

        return self::SUCCESS;
    }
}
