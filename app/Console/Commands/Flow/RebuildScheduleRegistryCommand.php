<?php

declare(strict_types=1);

namespace App\Console\Commands\Flow;

use App\Domains\Flow\Schedule\ScheduleRegistrySync;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites the landlord schedule registry of every active tenant from its `flow_triggers`, the truth
 * (adr-20261123-flow-schedule-trigger). A registry row lost after its tenant's commit would keep a schedule silent; this
 * pass bounds that to an hour. Wired to the scheduler in {@see routes/console.php} to run hourly. Exit code is always
 * 0 — a per-tenant failure is printed and logged and the pass continues.
 */
final class RebuildScheduleRegistryCommand extends Command
{
    /** @var string */
    protected $signature = 'flow:rebuild-schedule-registry';

    /** @var string */
    protected $description = 'Rebuild the landlord registry of schedule triggers from every tenant\'s triggers';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $switcher,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->tenants->findAllActive() as $tenant) {
            try {
                $this->switcher->runForTenant($tenant, function () use ($tenant): void {
                    $this->laravel->make(ScheduleRegistrySync::class)->rebuild($tenant->getId());
                });
            } catch (Throwable $exception) {
                $this->line("✘ {$tenant->getSlug()}: {$exception->getMessage()}");
                $this->logger->error('flow.schedule.registry_rebuild_failed', [
                    'tenant_id' => $tenant->getId(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
