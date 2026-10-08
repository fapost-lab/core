<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Assistant\Contracts\AssistantRepositoryInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts one flow in response to a resolved event trigger. Fanned out one job
 * per subscribed trigger by {@see DispatchFlowTriggerEventJob} so a single
 * failing subscriber never blocks the others, with independent retry.
 *
 * The started flow runs for the *emitting* contact (the `emit_event` node's
 * originating contact, carried in the event source). Authorization is the
 * trigger configuration itself — a tenant admin explicitly wired this flow to
 * this event — so the contact-facing access policy (public/auth gate for inbound
 * starts) is intentionally not applied here.
 *
 * The event payload is exposed to the started flow under `flow.event.*`.
 */
final class StartFlowFromEventJob implements ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $flowId,
        public readonly string $assistantId,
        public readonly string $contactId,
        public readonly array $payload,
        public readonly string $eventName,
    ) {
        $this->onQueue('scheduled.triggers');
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
        FlowDefinitionRepositoryInterface $definitions,
        ContactServiceInterface $contacts,
        AssistantRepositoryInterface $assistants,
        CurrentAssistantInterface $currentAssistant,
        FlowEngineInterface $engine,
        FlowExecutionGuardInterface $guard,
        LoggerInterface $logger,
    ): void {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use (
            $definitions,
            $contacts,
            $assistants,
            $currentAssistant,
            $engine,
            $guard,
            $logger,
        ): void {
            $definition = $definitions->findLatestActiveByFlowId($this->flowId);

            if (null === $definition) {
                $logger->warning('flow.event.start_skipped_no_active_flow', [
                    'tenant_id'  => $this->tenantId,
                    'flow_id'    => $this->flowId,
                    'event_name' => $this->eventName,
                ]);

                return;
            }

            // A stale/deleted trigger can reference a contact or assistant that no
            // longer exists — skip gracefully rather than crashing the job.
            try {
                $contact   = $contacts->findById($this->contactId);
                $assistant = $assistants->findById($this->assistantId);
            } catch (Throwable $exception) {
                $logger->warning('flow.event.start_skipped_missing_context', [
                    'tenant_id'    => $this->tenantId,
                    'contact_id'   => $this->contactId,
                    'assistant_id' => $this->assistantId,
                    'event_name'   => $this->eventName,
                    'error'        => $exception->getMessage(),
                ]);

                return;
            }

            $currentAssistant->set($assistant);

            // Outside the routing pipeline: claim the contact's session lock so the
            // start cannot race an inbound message for the same contact and assistant.
            $guard->run(
                tenantId: (string)$contact->tenant_id,
                contactId: (string)$contact->getKey(),
                assistantId: $this->assistantId,
                callback: fn () => $engine->start($definition, $contact, [
                    'flow' => ['event' => $this->payload],
                ]),
            );
        });
    }
}
