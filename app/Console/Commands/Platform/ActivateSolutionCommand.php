<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Exceptions\SolutionEntitlementUnavailableException;
use App\Domains\Solution\Exceptions\SolutionNotEntitledException;
use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;

/**
 * Turns an installed Solution on for one tenant. Idempotent. Refused, like on the screen, for a Solution the operator
 * does not entitle the tenant to, or while the operator's answer cannot be had; there is no way around it.
 */
final class ActivateSolutionCommand extends SolutionTenantCommand
{
    /** @var string */
    protected $signature = 'solutions:activate {solution : Solution id} {--tenant= : Slug of the tenant (required)}';

    /** @var string */
    protected $description = 'Activate an installed Solution for a tenant';

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher): int
    {
        $solution = (string) $this->argument('solution');

        return $this->runForTenantOption($tenants, $switcher, function () use ($solution): int {
            try {
                $change = $this->laravel->make(SolutionActivator::class)->activate($solution, ActivationActor::console());
            } catch (SolutionNotInstalledException|SolutionNotEntitledException|SolutionEntitlementUnavailableException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            if (ActivationChange::Unchanged === $change) {
                $this->components->info(sprintf('Solution "%s" was already active for tenant "%s".', $solution, $this->option('tenant')));
            } else {
                $this->components->info(sprintf('Solution "%s" is now active for tenant "%s".', $solution, $this->option('tenant')));
            }

            return self::SUCCESS;
        });
    }
}
