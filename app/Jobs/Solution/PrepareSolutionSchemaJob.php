<?php

declare(strict_types=1);

namespace App\Jobs\Solution;

use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Solution\Services\SolutionSchemas;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Brings a Solution's tables in one tenant's schema up to the installed package, outside the step or request that
 * found them missing or behind: a Solution the operator grants has no activation in which to prepare them, and a
 * failed upgrade leaves a schema behind. Until it has run, the Solution answers `solution_not_ready` for the tenant.
 *
 * Unique per tenant and Solution, so every refused step does not queue another. It runs on the long connection, on a
 * queue of its own, and is not gated by the tenant access mode: preparing tables runs no tenant code and is safe to
 * repeat. A Solution no longer installed by the time it runs is nothing to prepare.
 */
final class PrepareSolutionSchemaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string CONNECTION = 'redis-long';

    public const string QUEUE = 'solutions.migrate';

    public int $tries = 5;

    public int $timeout = 600;

    /** Seconds the uniqueness lock outlives a job that died without releasing it. */
    public int $uniqueFor = 1800;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $solutionId,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->tenantId . ':' . $this->solutionId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher, SolutionSchemas $schemas): void
    {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($schemas): void {
            try {
                $schemas->bringCurrent($this->solutionId);
            } catch (SolutionNotInstalledException) {
                // Removed from the image since the step asked: nothing to prepare.
            }
        });
    }
}
