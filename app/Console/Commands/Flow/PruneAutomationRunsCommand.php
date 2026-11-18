<?php

declare(strict_types=1);

namespace App\Console\Commands\Flow;

use App\Domains\Flow\Retention\AutomationRunPruner;
use App\Domains\Flow\Retention\AutomationRunRetention;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletes every active tenant's finished automation runs older than their retention, with their history and logs.
 *
 * Retention is per install: the platform settings `flow.automation_runs.succeeded_retention_days` (7) and
 * `flow.automation_runs.failed_retention_days` (30); 0 keeps that class forever. Conversation sessions and runs that
 * are still going are never touched — {@see AutomationRunPruner} says exactly what goes. Deleting is irreversible.
 */
final class PruneAutomationRunsCommand extends Command
{
    /** @var string */
    protected $signature = 'flow:prune-automation-runs {--dry-run : Count the runs that would be deleted, delete nothing}';

    /** @var string */
    protected $description = 'Deletes finished automation runs older than their retention period for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly PlatformSettingsReaderInterface $platformSettings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $succeededDays = $this->platformSettings->integer(AutomationRunRetention::SUCCEEDED_SETTING);
        $failedDays    = $this->platformSettings->integer(AutomationRunRetention::FAILED_SETTING);

        if ($succeededDays <= 0 && $failedDays <= 0) {
            $this->info('Automation run retention is disabled (keep forever); set it in Platform settings or with platform:settings:set. Nothing to prune.');

            return self::SUCCESS;
        }

        $now             = CarbonImmutable::now();
        $succeededCutoff = $succeededDays > 0 ? $now->subDays($succeededDays) : null;
        $failedCutoff    = $failedDays > 0 ? $now->subDays($failedDays) : null;
        $dryRun          = (bool) $this->option('dry-run');
        $deleted         = 0;
        $failed          = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $tenantDeleted = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): int => $this->laravel->make(AutomationRunPruner::class)
                        ->prune($tenant->getId(), $succeededCutoff, $failedCutoff, $dryRun),
                );

                $deleted += $tenantDeleted;

                $this->line("✔ {$slug}: " . ($dryRun ? 'would delete' : 'deleted') . " {$tenantDeleted}");
            } catch (Throwable $throwable) {
                $this->line("✘ {$slug}: {$throwable->getMessage()}");
                ++$failed;
            }
        }

        logger()->info('flow automation run retention prune finished', [
            'succeeded_retention_days' => $succeededDays,
            'failed_retention_days'    => $failedDays,
            'deleted_sessions'         => $deleted,
            'failed_tenants'           => $failed,
            'dry_run'                  => $dryRun,
        ]);

        $this->info(($dryRun ? 'Sessions to delete: ' : 'Deleted sessions: ') . $deleted);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
