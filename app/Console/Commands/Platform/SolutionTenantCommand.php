<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Closure;
use Illuminate\Console\Command;

/**
 * Base of the `solutions:activate|deactivate|list` commands: finds the tenant named by the required
 * `--tenant=<slug>` and runs the work inside that tenant's context. There is no "every tenant" form.
 */
abstract class SolutionTenantCommand extends Command
{
    /**
     * @param  Closure(): int  $work  runs switched into the tenant and returns the exit code
     */
    protected function runForTenantOption(TenantRepositoryInterface $tenants, TenantSwitcher $switcher, Closure $work): int
    {
        $slug = $this->option('tenant');

        if (!is_string($slug) || '' === $slug) {
            $this->components->error('The --tenant=<slug> option is required.');

            return self::FAILURE;
        }

        $tenant = $tenants->findBySlug($slug);

        if (null === $tenant || !$tenant->isActive()) {
            $this->components->error(sprintf('No active tenant with the slug "%s".', $slug));

            return self::FAILURE;
        }

        return (int) $switcher->runForTenant($tenant, $work);
    }
}
