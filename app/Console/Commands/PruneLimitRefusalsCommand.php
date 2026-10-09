<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Contact\Services\LimitRefusalRecorder;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Removes every tenant's limit refusals older than `quota.refusals_retention_days`.
 */
final class PruneLimitRefusalsCommand extends Command
{
    /** @var string */
    protected $signature = 'limit-refusals:prune';

    /** @var string */
    protected $description = 'Deletes limit refusals older than the retention period for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
    ) {
        parent::__construct();
    }

    public function handle(LimitRefusalRecorder $refusals): int
    {
        $days   = max(1, (int) config('quota.refusals_retention_days', 90));
        $cutoff = CarbonImmutable::now()->subDays($days);
        $failed = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            try {
                $removed = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    static fn (): int => $refusals->pruneBefore($cutoff),
                );

                if ($removed > 0) {
                    $this->line(sprintf('%s: removed %d row(s).', $tenant->getSlug(), $removed));
                }
            } catch (Throwable $throwable) {
                $this->line(sprintf('%s: %s', $tenant->getSlug(), $throwable->getMessage()));
                ++$failed;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
