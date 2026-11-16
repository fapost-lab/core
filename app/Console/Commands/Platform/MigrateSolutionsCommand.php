<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Solution\Exceptions\SolutionMigrationFailedException;
use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\SolutionSchemas;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Throwable;

/**
 * Brings Solutions' tables in tenant schemas up to the installed packages.
 *
 * With `--tenant=<slug>`: the Solutions active for that tenant (granted ones included, so an operator, or the SaaS
 * shell after a grant, prepares them at once), those it stored as active and those already present in its schema.
 * Without it, every active tenant, but only the Solutions each schema stores as active or already holds tables of
 * ({@see SolutionSchemas::idsToKeepCurrent()}): this is what `platform:update` runs, and it never asks the operator who
 * is entitled to what, so an upgrade costs the same whatever the number of tenants that never used a Solution.
 * `--solution=<id>` narrows either form to one of those Solutions; with `--tenant`, naming any other is an error. A failed tenant does not stop the others; the
 * exit code is a failure if any failed, and that tenant's Solution answers `solution_not_ready` until a run succeeds.
 */
final class MigrateSolutionsCommand extends SolutionTenantCommand
{
    /** @var string */
    protected $signature = 'solutions:migrate {--tenant= : Slug of one tenant; every active tenant when omitted} {--solution= : Only this Solution id}';

    /** @var string */
    protected $description = "Apply Solutions' pending tenant migrations";

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher): int
    {
        $only = $this->option('solution');
        $only = is_string($only) && '' !== $only ? $only : null;

        if (null !== $this->option('tenant')) {
            return $this->runForTenantOption($tenants, $switcher, fn (): int => $this->migrateTenant((string) $this->option('tenant'), $only, true));
        }

        $failed = false;

        foreach ($tenants->findAllActive() as $tenant) {
            /** @var TenantInterface $tenant */
            try {
                $failed = 0 !== $switcher->runForTenant($tenant, fn (): int => $this->migrateTenant($tenant->getSlug(), $only, false)) || $failed;
            } catch (Throwable $throwable) {
                $this->line(sprintf('✘ %s: %s', $tenant->getSlug(), $throwable->getMessage()));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function migrateTenant(string $slug, ?string $only, bool $includeActive): int
    {
        $schemas = $this->laravel->make(SolutionSchemas::class);
        $ids     = $schemas->idsToKeepCurrent();

        if ($includeActive) {
            $ids = [...$ids, ...$this->laravel->make(ActiveSolutions::class)->ids()];
        }

        $ids = array_values(array_unique($ids));

        if (null !== $only) {
            if ($includeActive && !in_array($only, $ids, true)) {
                $this->line(sprintf('✘ %s %s: the Solution is neither on for this tenant nor present in its schema', $slug, $only));

                return self::FAILURE;
            }

            $ids = array_values(array_intersect($ids, [$only]));
        }

        sort($ids);
        $status = self::SUCCESS;

        foreach ($ids as $id) {
            try {
                $applied = $schemas->bringCurrent($id);
            } catch (SolutionNotInstalledException|SolutionMigrationFailedException $exception) {
                $this->line(sprintf('✘ %s %s: %s', $slug, $id, $exception->getMessage()));
                $status = self::FAILURE;

                continue;
            }

            if ([] !== $applied) {
                $this->line(sprintf('✔ %s %s: %s', $slug, $id, implode(', ', $applied)));
            }
        }

        return $status;
    }
}
