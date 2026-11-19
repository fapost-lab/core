<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
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
 * Starts the contactless run a trigger fired, once per fire key ({@see AutomationRunner::start()}); its first segment
 * runs here, the rest in {@see ContinueAutomationRunJob}. A retry finds the run it already created and continues it.
 *
 * A Core event dispatches it ({@see DispatchFlowTriggerEventJob}), with the event id as the fire key and the event's
 * chain depth; the webhook trigger dispatches it ({@see \App\Domains\Flow\Webhook\FlowWebhookReceiver}), with the
 * delivery id as the fire key; the schedule trigger of spec `flow-automations` will too. The depth is read with a
 * fallback: a job queued before it existed unserializes without it.
 *
 * A busy trigger lock — another run of the same trigger is executing a segment — puts the job back in the queue
 * instead of spending an attempt; {@see retryUntil()} bounds how long it waits. A stopped tenant drops the start: the
 * fire belongs to a moment that has passed.
 */
final class StartAutomationRunJob implements ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;

    public const int LOCK_BUSY_RELEASE_SECONDS = 10;

    /** Below the `redis` connection's `retry_after` (90 s): a running segment is never reserved a second time. */
    public int $timeout = 60;

    /** Real failures end the job after three; a busy lock only releases it, until {@see retryUntil()}. */
    public int $maxExceptions = 3;

    /**
     * @param  array<string, mixed>  $initialState  merged into the new session's state (the trigger payload)
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $flowId,
        public readonly string $triggerId,
        public readonly string $fireKey,
        public readonly array $initialState = [],
        public readonly ?string $assistantId = null,
        public readonly int $chainDepth = 0,
    ) {
        $this->onQueue(AutomationRunner::QUEUE);
    }

    /**
     * {@see AutomationRunner::START_RETRY_SECONDS} (an hour) of busy-lock releases, plus one postponement while the
     * tenant is stopped: a copy the access-mode middleware postpones is dispatched again and gets its own deadline.
     */
    public function retryUntil(): DateTimeInterface
    {
        return Carbon::now()
            ->addSeconds(RespectsTenantAccessMode::POSTPONE_SECONDS)
            ->addSeconds(AutomationRunner::START_RETRY_SECONDS);
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
        return [new RespectsTenantAccessMode(StoppedTenantAction::Drop)];
    }

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher, AutomationRunner $runner): void
    {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        try {
            $switcher->runForTenant($tenant, fn () => $runner->start(
                $this->tenantId,
                $this->flowId,
                $this->triggerId,
                $this->fireKey,
                $this->initialState,
                $this->assistantId,
                $this->chainDepth ?? 0,
            ));
        } catch (SessionLockTimeoutException) {
            $this->release(self::LOCK_BUSY_RELEASE_SECONDS);
        }
    }
}
