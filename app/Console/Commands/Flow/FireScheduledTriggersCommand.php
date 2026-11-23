<?php

declare(strict_types=1);

namespace App\Console\Commands\Flow;

use App\Domains\Flow\Schedule\ScheduleRegistrySync;
use App\Domains\Flow\Schedule\ScheduleTriggerFirer;
use App\Domains\Tenancy\Contracts\FlowScheduleRegistryInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantAccessStates;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The minute's pass of schedule triggers (adr-20261123-flow-schedule-trigger). It asks the landlord registry which
 * tenants have a slot due, enters only those schemas, claims their due slots there ({@see ScheduleTriggerFirer}) and
 * then rewrites the tenant's registry rows from its triggers. A tenant that is not active or is stopped claims
 * nothing; its slots are missed once it is back, never caught up. Wired to the scheduler in {@see routes/console.php}
 * to run every minute. Exit code is always 0 — a per-tenant failure is printed and logged and the pass continues.
 */
final class FireScheduledTriggersCommand extends Command
{
    /**
     * The most tenants one pass asks for. Generous on purpose: a stopped tenant's rows stay due and are asked for
     * every minute, so a small cap could leave active tenants behind them.
     */
    public const int MAX_TENANTS_PER_PASS = 10000;

    /** @var string */
    protected $signature = 'flow:fire-scheduled-triggers';

    /** @var string */
    protected $description = 'Start the runs of schedule triggers whose slot is due, in the tenants that have one';

    public function __construct(
        private readonly FlowScheduleRegistryInterface $registry,
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $switcher,
        private readonly TenantAccessStates $accessStates,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now()->utc();

        foreach ($this->registry->dueTenantIds($now, self::MAX_TENANTS_PER_PASS) as $tenantId) {
            $tenant = null;

            try {
                $tenant = $this->tenants->findById($tenantId);

                if (null === $tenant) {
                    // The tenant is gone: its rows are left over.
                    $this->registry->forgetTenant($tenantId);

                    continue;
                }

                if (! $tenant->isActive() || $this->accessStates->stateFor($tenantId)->isStopped()) {
                    continue;
                }

                $this->switcher->runForTenant($tenant, function () use ($tenant, $now): void {
                    // Resolved inside the tenant: the firer and its collaborators are scoped.
                    $report = $this->laravel->make(ScheduleTriggerFirer::class)->fireDue($tenant->getId(), $now);
                    $this->laravel->make(ScheduleRegistrySync::class)->rebuild($tenant->getId());

                    if ($report['fired'] + $report['skipped'] + $report['missed'] > 0) {
                        $this->line(sprintf(
                            '✔ %s: fired=%d, skipped=%d, missed=%d',
                            $tenant->getSlug(),
                            $report['fired'],
                            $report['skipped'],
                            $report['missed'],
                        ));
                    }
                });
            } catch (Throwable $exception) {
                $label = $tenant?->getSlug() ?? $tenantId;
                $this->line("✘ {$label}: {$exception->getMessage()}");
                $this->logger->error('flow.schedule.tenant_pass_failed', [
                    'tenant_id' => $tenantId,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
