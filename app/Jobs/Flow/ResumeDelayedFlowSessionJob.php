<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Orchestration\DelayedSessionResumer;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Wakes a session parked on a `delay` node once its `resume_at` is due. Queued
 * with the delay applied by {@see \App\Infrastructure\Flow\QueuedDelayResumeScheduler};
 * the staleness checks and the session lock live in {@see DelayedSessionResumer}.
 *
 * A busy lock puts the job back in the queue instead of spending an attempt: an automation run's trigger lock is
 * held for a whole segment by every other run of the trigger, and a contactless run has no inbound message that
 * would wake it otherwise. {@see retryUntil()} bounds the wait; real failures still end the job after three.
 * The wake-up stays on `flow.execution` for every kind of run: the delay node cannot tell the scheduler what started
 * the session, and an automation's wake-up runs at most one execution segment before the rest of the run moves to
 * `flow.automations`.
 */
final class ResumeDelayedFlowSessionJob implements ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;
    use ReleasesOnBusySessionLock;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $sessionId,
        public readonly string $nodeId,
        public readonly string $resumeAt,
    ) {
        $this->onQueue('flow.execution');
    }

    /**
     * An hour past the later of the wake-up time and now, plus one postponement while the tenant is stopped: a copy
     * the access-mode middleware postpones is dispatched again and gets its own deadline.
     */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::parse($this->resumeAt)
            ->max(CarbonImmutable::now())
            ->addSeconds(RespectsTenantAccessMode::POSTPONE_SECONDS)
            ->addHour();
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

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        DelayedSessionResumer $resumer,
    ): void {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $this->releasingOnBusyLock(fn () => $switcher->runForTenant($tenant, function () use ($resumer): void {
            $resumer->resume($this->sessionId, $this->nodeId, CarbonImmutable::parse($this->resumeAt));
        }));
    }
}
