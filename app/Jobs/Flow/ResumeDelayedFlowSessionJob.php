<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Orchestration\DelayedSessionResumer;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Wakes a session parked on a `delay` node once its `resume_at` is due. Queued
 * with the delay applied by {@see \App\Infrastructure\Flow\QueuedDelayResumeScheduler};
 * the staleness checks and the session lock live in {@see DelayedSessionResumer}.
 */
final class ResumeDelayedFlowSessionJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $sessionId,
        public readonly string $nodeId,
        public readonly string $resumeAt,
    ) {
        $this->onQueue('flow.execution');
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

        $switcher->runForTenant($tenant, function () use ($resumer): void {
            $resumer->resume($this->sessionId, $this->nodeId, CarbonImmutable::parse($this->resumeAt));
        });
    }
}
