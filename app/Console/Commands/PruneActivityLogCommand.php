<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Audit\Services\ActivityLogPruner;
use App\Domains\Audit\Services\AuditRetention;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Deletes every active tenant's audit log entries older than the retention.
 *
 * Retention is per install, the platform setting `audit.retention_days` (Platform settings, or `AUDIT_RETENTION_DAYS`
 * as its default, 365); 0 keeps the log forever and the command does nothing. The package's own `activitylog:clean`
 * is not used: it does not walk the tenants and would clean only the schema it happens to run in.
 */
final class PruneActivityLogCommand extends Command
{
    /** @var string */
    protected $signature = 'audit:prune {--dry-run : Count the entries that would be deleted, delete nothing}';

    /** @var string */
    protected $description = 'Deletes audit log entries older than the retention period for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly PlatformSettingsReaderInterface $platformSettings,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $retentionDays = $this->platformSettings->integer(AuditRetention::SETTING);

        if ($retentionDays <= 0) {
            $this->info('Audit log retention is disabled (keep forever); set it in Platform settings or with platform:settings:set. Nothing to prune.');

            return self::SUCCESS;
        }

        $dryRun  = (bool) $this->option('dry-run');
        $cutoff  = CarbonImmutable::now()->subDays($retentionDays);
        $deleted = 0;
        $failed  = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $tenantDeleted = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): int => $this->laravel->make(ActivityLogPruner::class)->prune($cutoff, $dryRun),
                );

                $deleted += $tenantDeleted;

                $this->line("✔ {$slug}: " . ($dryRun ? 'would delete' : 'deleted') . " {$tenantDeleted}");
            } catch (Throwable $throwable) {
                $this->line("✘ {$slug}: {$throwable->getMessage()}");
                ++$failed;
            }
        }

        logger()->info('audit log retention prune finished', [
            'retention_days'  => $retentionDays,
            'deleted_entries' => $deleted,
            'failed_tenants'  => $failed,
            'dry_run'         => $dryRun,
        ]);

        $this->info(($dryRun ? 'Entries to delete: ' : 'Deleted entries: ') . $deleted);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
