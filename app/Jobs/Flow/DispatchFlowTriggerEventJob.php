<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Events\CoreEventCatalog;
use App\Domains\Flow\Models\TenantEvent;
use App\Domains\Flow\Services\ResolveEventTriggersService;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
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
 * A Core event ({@see CoreEventCatalog}) fans out one {@see StartAutomationRunJob} per resolved trigger: a contactless
 * run whose fire key is the event id, so a retry of this job starts each run once. The trigger's assistant, if any,
 * is the run's; the event's payload is its `flow.event.*`.
 *
 * A flow's own event (`emit_event`) fans out one {@see StartFlowFromEventJob} per resolved trigger for isolation
 * and independent retry. The started flow runs for the emitting contact
 * (`source.contact_id`); events without a contact are skipped (a flow start
 * requires a contact), and so are triggers without an assistant.
 *
 * Every started run carries the event's chain depth. Jobs queued before the id and the depth existed unserialize
 * without them, so both are read with a fallback.
 */
final class DispatchFlowTriggerEventJob implements ShouldQueue, TenantAccessGatedJob
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
        public readonly ?string $eventId = null,
        public readonly int $chainDepth = 1,
    ) {
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
            if (CoreEventCatalog::has($this->eventName)) {
                $this->startAutomationRuns($resolver);

                return;
            }

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
                    $this->depth(),
                );
            }
        });
    }

    /**
     * A Core event starts a contactless run on every active trigger that listens to it, whatever the trigger's
     * assistant: the event belongs to the tenant.
     */
    private function startAutomationRuns(ResolveEventTriggersService $resolver): void
    {
        $eventId = $this->eventId ?? null;

        if (null === $eventId || '' === $eventId) {
            // Only the publisher queues a Core event, always with an id; without one a retry could not be deduplicated.
            Log::channel('stack')->warning('flow.event.core_event_without_id', [
                'tenant_id'  => $this->tenantId,
                'event_name' => $this->eventName,
            ]);

            return;
        }

        foreach ($resolver->execute($this->tenantId, $this->eventName) as $trigger) {
            $assistantId = is_string($trigger->metadata['assistant_id'] ?? null) && '' !== $trigger->metadata['assistant_id']
                ? $trigger->metadata['assistant_id']
                : null;

            StartAutomationRunJob::dispatch(
                $this->tenantId,
                $trigger->flowId,
                $trigger->triggerId,
                $eventId,
                ['flow' => ['event' => $this->payload]],
                $assistantId,
                $this->depth(),
            );
        }
    }

    /**
     * The event's chain depth; 1 for a job queued before the depth existed.
     */
    private function depth(): int
    {
        return $this->chainDepth ?? 1;
    }

    /**
     * Best-effort registration of the event name in the tenant registry. The
     * registry powers UI selectors (list of known events) and is allowed to
     * fail silently — the published event is independent of registry state.
     */
    private function registerEventName(): void
    {
        if (CoreEventCatalog::isReserved($this->eventName)) {
            return;
        }

        TenantEvent::query()->updateOrCreate(
            ['tenant_id' => $this->tenantId, 'event_name' => $this->eventName],
            [],
        );
    }
}
