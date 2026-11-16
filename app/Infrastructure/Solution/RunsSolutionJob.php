<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantAccessStates;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Closure;
use Fapost\Foundation\Solution\Queue\SolutionJob;
use Fapost\Foundation\Solution\Queue\SolutionJobMiddlewareInterface;
use Fapost\Foundation\Solution\Queue\SolutionJobRejectedException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Core's gate in front of every Solution job; `SolutionJob::middleware()` returns it and cannot be overridden.
 *
 * - No tenant stamped: the job was queued around {@see CoreSolutionQueue}; it fails, with the reason.
 * - The tenant is gone: dropped.
 * - The tenant is stopped: a copy is queued again an hour later (runtime work stands still while stopped).
 * - Switched into the tenant: the Solution is no longer installed or active there → dropped; its tables are not ready →
 *   their preparation is queued and a copy of the job comes back a minute later. Otherwise the job runs.
 *
 * A put-back copy does not spend an attempt, so a long wait cannot exhaust the job's tries. A copy put back because the
 * Solution's tables are not ready counts in {@see SolutionJob::$postponements}: after {@see self::MAX_POSTPONEMENTS} the
 * job is dropped with a warning. A stopped tenant's job waits without a limit, like Core's runtime jobs. On the sync
 * connection a delay means nothing, so there the job is dropped at once. Nothing is held between jobs but the
 * container: the tenant context and {@see ActiveSolutions} are scoped, and `runForTenant()` always switches back.
 */
final readonly class RunsSolutionJob implements SolutionJobMiddlewareInterface
{
    public const int STOPPED_DELAY_SECONDS = 3600;

    public const int NOT_READY_DELAY_SECONDS = 60;

    public const int MAX_POSTPONEMENTS = 48;

    public function __construct(
        private Container $container,
    ) {
    }

    public function handle(SolutionJob $job, Closure $next): mixed
    {
        if (null === $job->tenantId) {
            throw SolutionJobRejectedException::noTenant($job::class);
        }

        $tenant = $this->container->make(TenantRepositoryInterface::class)->findById($job->tenantId);

        if (null === $tenant) {
            $this->drop($job, 'tenant_gone');

            return null;
        }

        if ($this->container->make(TenantAccessStates::class)->stateFor($job->tenantId)->isStopped()) {
            $this->postpone($job, self::STOPPED_DELAY_SECONDS, 'tenant_stopped', counts: false);

            return null;
        }

        return $this->container->make(TenantSwitcher::class)->runForTenant($tenant, function () use ($job, $next): mixed {
            $solutionId = $job->solution();

            if (!$this->container->make(ActiveSolutions::class)->isActive($solutionId)) {
                $this->drop($job, 'solution_inactive');

                return null;
            }

            if (!$this->container->make(SolutionPreparation::class)->isReadyOrPrepare($solutionId)) {
                $this->postpone($job, self::NOT_READY_DELAY_SECONDS, 'solution_not_ready', counts: true);

                return null;
            }

            return $next($job);
        });
    }

    /**
     * @param  bool  $counts  whether this put-back counts towards the limit: only a schema that stays not ready does; a stopped
     *                        tenant waits as long as Core's own runtime jobs do
     */
    private function postpone(SolutionJob $job, int $seconds, string $reason, bool $counts): void
    {
        $connection = $job->connection ?? config('queue.default');

        if ('sync' === config("queue.connections.{$connection}.driver")) {
            $this->drop($job, $reason);

            return;
        }

        if ($counts && $job->postponements >= self::MAX_POSTPONEMENTS) {
            $this->logger()->warning('solution_job.dropped', $this->context($job, $reason) + ['postponements' => $job->postponements, 'limit' => self::MAX_POSTPONEMENTS]);

            return;
        }

        $copy                = clone $job;
        $copy->postponements = $job->postponements + ($counts ? 1 : 0);
        $copy->delay         = $seconds;

        $this->container->make(Dispatcher::class)->dispatch($copy);
        $this->logger()->info('solution_job.postponed', $this->context($job, $reason) + ['delay' => $seconds, 'postponements' => $copy->postponements]);
    }

    private function drop(SolutionJob $job, string $reason): void
    {
        $this->logger()->info('solution_job.dropped', $this->context($job, $reason));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(SolutionJob $job, string $reason): array
    {
        return ['job' => $job::class, 'solution_id' => $job->solution(), 'tenant_id' => $job->tenantId, 'reason' => $reason];
    }

    private function logger(): LoggerInterface
    {
        return $this->container->make(LoggerInterface::class);
    }
}
