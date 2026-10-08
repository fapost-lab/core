<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Queue;

use App\Domains\Tenancy\Services\TenantAccessStates;
use Closure;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Job middleware for runtime work: asks the access mode of the job's tenant before the job runs.
 *
 * Active tenant: the job runs. Stopped tenant: the job does not run, and {@see StoppedTenantAction}
 * says whether it is dropped or postponed. A postponed job is queued again as a copy, delayed by
 * {@see self::POSTPONE_SECONDS}, rather than released: a release counts as an attempt and a
 * wake-up that waits a month would exhaust its tries.
 *
 * Nothing is kept on the instance but the action: the operator is resolved per call, since a
 * worker outlives any binding it could capture.
 */
final readonly class RespectsTenantAccessMode
{
    public const int POSTPONE_SECONDS = 3600;

    public function __construct(
        private StoppedTenantAction $whenStopped,
    ) {
    }

    /**
     * Runs the job when its tenant is active, and otherwise drops or postpones it. When the operator
     * cannot be asked the tenant counts as active ({@see TenantAccessStates}).
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (! $job instanceof TenantAccessGatedJob) {
            throw new LogicException($job::class . ' uses RespectsTenantAccessMode and must implement ' . TenantAccessGatedJob::class . '.');
        }

        $tenantId = $job->accessModeTenantId();

        if (! app(TenantAccessStates::class)->stateFor($tenantId)->isStopped()) {
            return $next($job);
        }

        // A sync connection ignores the delay, so a postponed copy would run at once and be postponed
        // again, without end. There is nothing to wait on: the work is dropped.
        if (StoppedTenantAction::Postpone === $this->whenStopped && ! $this->runsOnSyncConnection($job)) {
            $this->postpone($job, $tenantId);

            return null;
        }

        Log::info('tenant.access_mode.job_dropped', ['job' => $job::class, 'tenant_id' => $tenantId]);

        if ($job instanceof DefersWhenDroppedWhileStopped) {
            $job->deferUntilActive();
        }

        return null;
    }

    private function runsOnSyncConnection(object $job): bool
    {
        $connection = $job->connection ?? config('queue.default');

        return 'sync' === config("queue.connections.{$connection}.driver");
    }

    private function postpone(object $job, string $tenantId): void
    {
        $copy      = clone $job;
        $copy->job = null;

        dispatch($copy)->delay(self::POSTPONE_SECONDS);

        Log::info('tenant.access_mode.job_postponed', [
            'job'       => $job::class,
            'tenant_id' => $tenantId,
            'delay'     => self::POSTPONE_SECONDS,
        ]);
    }
}
