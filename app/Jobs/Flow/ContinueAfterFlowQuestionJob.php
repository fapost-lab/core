<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Orchestration\FlowQuestionResumer;
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
 * Continues the run of an answered `ask_person` question, under the run's lock ({@see FlowQuestionResumer}).
 *
 * A busy lock puts the job back instead of spending an attempt, as {@see ResumeDelayedFlowSessionJob} does: an
 * automation run's trigger lock is held for a whole segment by every other run of the trigger. {@see retryUntil()}
 * bounds the wait; real failures end the job after three. The question's own deadline wake-up stays queued meanwhile
 * and reads the answer too, so a job that gives up loses nothing but time.
 */
final class ContinueAfterFlowQuestionJob implements ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;
    use ReleasesOnBusySessionLock;

    public readonly string $queuedAt;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $questionId,
    ) {
        $this->queuedAt = CarbonImmutable::now()->toIso8601String();
        $this->onQueue('flow.execution');
    }

    /**
     * An hour past the moment it was queued, plus one postponement while the tenant is stopped.
     */
    public function retryUntil(): DateTimeInterface
    {
        return CarbonImmutable::parse($this->queuedAt)
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

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher, FlowQuestionResumer $resumer): void
    {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $this->releasingOnBusyLock(fn () => $switcher->runForTenant($tenant, function () use ($resumer): void {
            $resumer->continue($this->questionId);
        }));
    }
}
