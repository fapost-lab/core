<?php

declare(strict_types=1);

namespace App\Console\Commands\Flow;

use App\Domains\Flow\Orchestration\AutomationRunDeadlineSweeper;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantAccessStates;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fails the automation runs still live past their deadline, across every active tenant that is not stopped
 * ({@see AutomationRunDeadlineSweeper}). Wired to the scheduler in {@see routes/console.php} to run every minute. Exit
 * code is always 0 — a per-tenant failure is printed and the loop continues.
 */
final class SweepOverdueAutomationRunsCommand extends Command
{
    /** @var string */
    protected $signature = 'flow:sweep-overdue-automation-runs';

    /** @var string */
    protected $description = 'Fail automation runs that are still live past their deadline, or whose start was lost';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $switcher,
        private readonly TenantAccessStates $accessStates,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                // A stopped tenant runs nothing, and failing its runs is a change to it. Skipping only delays that:
                // the deadline is absolute, so the first pass after the tenant is active again catches what came due.
                if ($this->accessStates->stateFor($tenant->getId())->isStopped()) {
                    continue;
                }

                $this->switcher->runForTenant($tenant, function () use ($tenant, $slug): void {
                    // Resolved inside the tenant: the sweeper and its collaborators are scoped.
                    $report = $this->laravel->make(AutomationRunDeadlineSweeper::class)->sweep($tenant->getId());

                    if ($report['failed_runs'] > 0 || $report['skipped_busy'] > 0) {
                        $this->line(sprintf(
                            '✔ %s: failed_runs=%d, failed_sessions=%d, skipped_busy=%d',
                            $slug,
                            $report['failed_runs'],
                            $report['failed_sessions'],
                            $report['skipped_busy'],
                        ));
                    }
                });
            } catch (Throwable $exception) {
                $this->line("✘ {$slug}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
