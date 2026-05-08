<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Models\TenantEvent;
use App\Domains\Flow\Services\ResolveEventTriggersService;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Resolves event triggers for a published flow trigger event and starts each
 * subscribed flow asynchronously. Runs on the `scheduled.triggers` queue so
 * that high-priority transactional traffic is never blocked by event fanout.
 *
 * Design note: the actual flow-start mechanics for tenant-scoped events
 * (without an originating contact) is owned by the trigger pipeline (Phase D).
 * This job currently performs trigger resolution + event-name registration
 * and emits a structured log entry per resolved trigger. Wiring of the per-
 * trigger start job is added when the trigger pipeline lands.
 */
final class DispatchFlowTriggerEventJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $source
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $eventName,
        public readonly array $payload,
        public readonly array $source,
    ) {
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        ResolveEventTriggersService $resolver,
    ): void {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($resolver): void {
            $this->registerEventName();

            $resolved = $resolver->execute($this->tenantId, $this->eventName);

            foreach ($resolved as $trigger) {
                Log::channel('stack')->info('flow.event.trigger_resolved', [
                    'tenant_id'  => $this->tenantId,
                    'event_name' => $this->eventName,
                    'trigger_id' => $trigger->triggerId,
                    'flow_id'    => $trigger->flowId,
                    'source'     => $this->source,
                ]);
            }
        });
    }

    /**
     * Best-effort registration of the event name in the tenant registry. The
     * registry powers UI selectors (list of known events) and is allowed to
     * fail silently — the published event is independent of registry state.
     */
    private function registerEventName(): void
    {
        TenantEvent::query()->updateOrCreate(
            ['tenant_id' => $this->tenantId, 'event_name' => $this->eventName],
            [],
        );
    }
}
