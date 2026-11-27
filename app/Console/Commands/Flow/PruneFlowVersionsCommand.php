<?php

declare(strict_types=1);

namespace App\Console\Commands\Flow;

use App\Domains\Flow\Retention\FlowVersionPruner;
use App\Domains\Flow\Retention\FlowVersionRetention;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletes every active tenant's old published flow versions, keeping the last N of each flow.
 *
 * N is per install: the platform setting `flow.versions.keep_last`; 0, the default, keeps every version and the command
 * does nothing. A version that is active, the flow's highest, used by any session or persistent button, or pinned by a
 * subflow node is never deleted — {@see FlowVersionPruner} says exactly what goes. Deleting is irreversible.
 */
final class PruneFlowVersionsCommand extends Command
{
    /** @var string */
    protected $signature = 'flow:prune-versions {--dry-run : Count the versions that would be deleted, delete nothing}';

    /** @var string */
    protected $description = 'Deletes published flow versions older than the last N of each flow for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly PlatformSettingsReaderInterface $platformSettings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $keepLast = $this->platformSettings->integer(FlowVersionRetention::SETTING);

        if ($keepLast <= 0) {
            $this->info(sprintf(
                'Flow version retention is disabled (keep every version); set %s in Platform settings or with platform:settings:set (%d is recommended). Nothing to prune.',
                FlowVersionRetention::SETTING,
                FlowVersionRetention::RECOMMENDED_KEEP,
            ));

            return self::SUCCESS;
        }

        $dryRun  = (bool) $this->option('dry-run');
        $deleted = 0;
        $failed  = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $tenantDeleted = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): int => $this->laravel->make(FlowVersionPruner::class)
                        ->prune($tenant->getId(), $keepLast, $dryRun),
                );

                $deleted += $tenantDeleted;

                $this->line("✔ {$slug}: " . ($dryRun ? 'would delete' : 'deleted') . " {$tenantDeleted}");
            } catch (Throwable $throwable) {
                $this->line("✘ {$slug}: {$throwable->getMessage()}");
                ++$failed;
            }
        }

        logger()->info('flow version retention prune finished', [
            'keep_last'        => $keepLast,
            'deleted_versions' => $deleted,
            'failed_tenants'   => $failed,
            'dry_run'          => $dryRun,
        ]);

        $this->info(($dryRun ? 'Versions to delete: ' : 'Deleted versions: ') . $deleted);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
