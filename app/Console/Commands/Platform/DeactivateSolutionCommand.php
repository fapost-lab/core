<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;

/**
 * Turns a Solution off for one tenant, installed or not. Its data stays. Idempotent.
 */
final class DeactivateSolutionCommand extends SolutionTenantCommand
{
    /** @var string */
    protected $signature = 'solutions:deactivate {solution : Solution id} {--tenant= : Slug of the tenant (required)} {--reason= : Why, kept in the activation journal}';

    /** @var string */
    protected $description = 'Deactivate a Solution for a tenant; its data is kept';

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher): int
    {
        $solution = (string) $this->argument('solution');
        $reason   = $this->option('reason');

        if (is_string($reason) && mb_strlen($reason) > 255) {
            $this->components->error('The --reason must be at most 255 characters.');

            return self::FAILURE;
        }

        return $this->runForTenantOption($tenants, $switcher, function () use ($solution, $reason): int {
            $change = $this->laravel->make(SolutionActivator::class)->deactivate(
                $solution,
                ActivationActor::console(),
                is_string($reason) && '' !== $reason ? $reason : null,
            );

            if (ActivationChange::Unchanged === $change) {
                $this->components->info(sprintf('Solution "%s" was not active for tenant "%s".', $solution, $this->option('tenant')));
            } else {
                $this->components->info(sprintf('Solution "%s" is now inactive for tenant "%s".', $solution, $this->option('tenant')));
            }

            return self::SUCCESS;
        });
    }
}
