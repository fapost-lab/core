<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Orchestration\AutomationRunner;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Runs the next segment of an automation run ({@see AutomationRunner::continue()}), queued by the engine when the
 * previous segment's budget ran out. {@see $sessionId} is the run's root session; the job runs only while the root
 * still carries {@see $segmentId}: a retry of this job continues the run, a stale or duplicate copy does nothing.
 *
 * A busy lock puts the job back in the queue instead of spending an attempt; a stopped tenant postpones it, since
 * the run is under way and must not be lost.
 */
final class ContinueAutomationRunJob implements ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;
    use ReleasesOnBusySessionLock;

    /** Below the `redis` connection's `retry_after` (90 s): a running segment is never reserved a second time. */
    public int $timeout = 60;

    /**
     * @param  string  $sessionId  the id of the run's root session, never of a subflow child: the root alone carries
     *                             the `segment_id` (the scheduler port calls it `$rootSessionId`; the property keeps
     *                             its name so a copy already queued still unserializes)
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $sessionId,
        public readonly string $segmentId,
    ) {
        $this->onQueue(AutomationRunner::QUEUE);
    }

    /**
     * An hour of busy-lock releases, plus one postponement while the tenant is stopped: a copy the access-mode
     * middleware postpones is dispatched again and gets its own deadline.
     */
    public function retryUntil(): DateTimeInterface
    {
        return Carbon::now()->addSeconds(RespectsTenantAccessMode::POSTPONE_SECONDS)->addHour();
    }

    public function accessModeTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectsTenantAccessMode(StoppedTenantAction::Postpone)];
    }

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher, AutomationRunner $runner): void
    {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $this->releasingOnBusyLock(
            fn () => $switcher->runForTenant($tenant, fn () => $runner->continue($this->sessionId, $this->segmentId)),
        );
    }
}
