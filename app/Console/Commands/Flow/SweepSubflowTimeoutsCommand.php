<?php

declare(strict_types=1);

namespace App\Console\Commands\Flow;

use App\Domains\Flow\Subflow\SubflowTimeoutSweeper;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sweeps expired subflow chains across every active tenant. Wired to the
 * scheduler in {@see routes/console.php} to run every minute. Exit code is
 * always 0 — a per-tenant failure is logged and the loop continues.
 */
final class SweepSubflowTimeoutsCommand extends Command
{
    protected $signature = 'flow:sweep-subflow-timeouts';

    protected $description = 'Force-fail subflow children whose parent has expired and resume the parent through the failed handle';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $switcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $this->switcher->runForTenant($tenant, function () use ($slug): void {
                    $report = app(SubflowTimeoutSweeper::class)->sweep();

                    if ($report['forced_failures'] > 0 || $report['orphans_expired'] > 0) {
                        $this->line(sprintf(
                            '✔ %s: forced_failures=%d, orphans_expired=%d',
                            $slug,
                            $report['forced_failures'],
                            $report['orphans_expired'],
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
