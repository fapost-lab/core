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
 * Fans out one {@see StartFlowFromEventJob} per resolved trigger for isolation
 * and independent retry. The started flow runs for the emitting contact
 * (`source.contact_id`); events without a contact are skipped (a flow start
 * requires a contact).
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

            $contactId = is_string($this->source['contact_id'] ?? null) ? $this->source['contact_id'] : '';

            if ('' === $contactId) {
                // No originating contact — nothing to start a contact-scoped flow for.
                Log::channel('stack')->info('flow.event.dispatch_skipped_no_contact', [
                    'tenant_id'  => $this->tenantId,
                    'event_name' => $this->eventName,
                    'source'     => $this->source,
                ]);

                return;
            }

            foreach ($resolver->execute($this->tenantId, $this->eventName) as $trigger) {
                $assistantId = is_string($trigger->metadata['assistant_id'] ?? null)
                    ? $trigger->metadata['assistant_id']
                    : '';

                if ('' === $assistantId) {
                    continue;
                }

                StartFlowFromEventJob::dispatch(
                    $this->tenantId,
                    $trigger->flowId,
                    $assistantId,
                    $contactId,
                    $this->payload,
                    $this->eventName,
                );
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
