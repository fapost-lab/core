<?php

declare(strict_types=1);

namespace App\Console\Commands\Flow;

use App\Domains\Flow\Retention\SessionHistoryPruner;
use App\Domains\Flow\Retention\SessionHistoryRetention;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletes, for every active tenant, the history of conversation sessions that finished longer ago than the retention.
 *
 * Retention is per install: the platform setting `flow.session_history.retention_days`; 0, the default, keeps the
 * history forever and the command does nothing. Session rows stay; sessions that are still going, their subflow
 * children and automation runs are never touched — {@see SessionHistoryPruner} says exactly what goes. Deleting is
 * irreversible.
 */
final class PruneSessionHistoryCommand extends Command
{
    /** @var string */
    protected $signature = 'flow:prune-session-history {--dry-run : Count the sessions whose history would be deleted, delete nothing}';

    /** @var string */
    protected $description = 'Deletes the history of finished conversation sessions older than the retention period for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly PlatformSettingsReaderInterface $platformSettings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $retentionDays = $this->platformSettings->integer(SessionHistoryRetention::SETTING);

        if ($retentionDays <= 0) {
            $this->info('Session history retention is disabled (keep forever); set it in Platform settings or with platform:settings:set. Nothing to prune.');

            return self::SUCCESS;
        }

        $cutoff = CarbonImmutable::now()->subDays($retentionDays);
        $dryRun = (bool) $this->option('dry-run');
        $pruned = 0;
        $failed = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $tenantPruned = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): int => $this->laravel->make(SessionHistoryPruner::class)
                        ->prune($tenant->getId(), $cutoff, $dryRun),
                );

                $pruned += $tenantPruned;

                $this->line("✔ {$slug}: " . ($dryRun ? 'would prune' : 'pruned') . " {$tenantPruned}");
            } catch (Throwable $throwable) {
                $this->line("✘ {$slug}: {$throwable->getMessage()}");
                ++$failed;
            }
        }

        logger()->info('flow session history retention prune finished', [
            'retention_days'  => $retentionDays,
            'pruned_sessions' => $pruned,
            'failed_tenants'  => $failed,
            'dry_run'         => $dryRun,
        ]);

        $this->info(($dryRun ? 'Sessions whose history would be deleted: ' : 'Sessions whose history was deleted: ') . $pruned);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
