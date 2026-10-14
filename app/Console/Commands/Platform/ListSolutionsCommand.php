<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\SolutionActivation;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;

/**
 * Lists the installed Solutions and the stored activation rows of one tenant, orphans included, with the
 * effective state. Reads only.
 */
final class ListSolutionsCommand extends SolutionTenantCommand
{
    /** @var string */
    protected $signature = 'solutions:list {--tenant= : Slug of the tenant (required)}';

    /** @var string */
    protected $description = 'List the Solutions installed in this image and their activation for a tenant';

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher): int
    {
        return $this->runForTenantOption($tenants, $switcher, function (): int {
            $installed = $this->laravel->make(InstalledSolutions::class);
            $active    = $this->laravel->make(ActiveSolutions::class);
            $stored    = [];

            foreach ($this->laravel->make(SolutionActivationStoreInterface::class)->all() as $row) {
                $stored[$row->solutionId] = $row;
            }

            $ids = array_unique([...array_map(static fn ($solution): string => $solution->id, $installed->all()), ...array_keys($stored)]);
            sort($ids);

            $rows = [];

            foreach ($ids as $id) {
                $solution = $installed->find($id);
                $row      = $stored[$id] ?? null;

                $rows[] = [
                    $id,
                    $solution->name ?? '-',
                    $solution->version ?? '-',
                    null === $solution ? 'no' : 'yes',
                    $row instanceof SolutionActivation ? $row->status->value : 'none',
                    $active->isActive($id) ? 'active' : 'inactive',
                ];
            }

            if ([] === $rows) {
                $this->components->info('No Solutions are installed and none has been activated.');

                return self::SUCCESS;
            }

            $this->table(['Solution', 'Name', 'Version', 'Installed', 'Stored', 'Effective'], $rows);

            return self::SUCCESS;
        });
    }
}
