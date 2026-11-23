<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Assistant\Contracts\AssistantRepositoryInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Flow\Admission\TriggerAdmissionRequest;
use App\Domains\Flow\Admission\TriggerRunAdmission;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Services\ContactChannelProbe;
use App\Domains\Flow\Services\ContactFlowStartCollision;
use App\Domains\Flow\Services\FlowSessionTerminator;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
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
 * The event payload is exposed to the started flow under `flow.event.*`. The session records the event's chain
 * depth, so an event the flow raises in turn is one level deeper (read with a fallback: a job queued before the
 * depth existed unserializes without it).
 *
 * The start follows the rules of a `start_flow` start ({@see StartFlowForContactJob}), under the contact's session lock
 * with the trigger's assistant: it happens once per trigger and event (`start_key` = `event:` + a hash of the trigger id
 * and the event id, else of this job's own id), never for a contact without a channel on an active channel of that
 * assistant, and never over a busy contact ({@see ContactFlowStartCollision}, the fixed default window) — an operator
 * on the thread, a conversation with that assistant that moved within the window or sleeps in a `delay`, or the
 * conversation that emitted the event (`source.session_id`) still live. An older live conversation is ended first.
 * None of these outcomes is an exception: a retry would not change it.
 *
 * Run admission ({@see TriggerRunAdmission}) is asked under the lock, after the contact proved free, so a start skipped
 * for a busy contact or a missing channel is neither metered nor counted by the breaker. Its unit key is the trigger id
 * and what fired it, as the start key. A refused start starts nothing and ends no conversation.
 *
 * A job queued before the trigger, event and source session ids existed unserializes without them: it starts without
 * admission, its start key comes from the job's own id, and no conversation counts as the emitting one.
 *
 * A flow switched off in the flows list starts nothing, checked before the contact's channel, its collisions and run
 * admission, so it is neither metered nor counted: the event is not caught up once the flow is turned back on.
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
        public readonly int $chainDepth = 1,
        public readonly ?string $triggerId = null,
        public readonly ?string $eventId = null,
        public readonly ?string $sourceSessionId = null,
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
        FlowDraftRepositoryInterface $drafts,
        ContactServiceInterface $contacts,
        AssistantRepositoryInterface $assistants,
        CurrentAssistantInterface $currentAssistant,
        FlowEngineInterface $engine,
        FlowExecutionGuardInterface $guard,
        FlowSessionRepositoryInterface $sessions,
        ContactChannelProbe $channels,
        ContactFlowStartCollision $collision,
        FlowSessionTerminator $terminator,
        TriggerRunAdmission $admission,
        LoggerInterface $logger,
    ): void {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        // Computed once: a job without an event id is keyed by its own id, the same on every retry.
        $fireIdentity = $this->fireIdentity();
        $startKey     = $this->startKey($fireIdentity);

        $switcher->runForTenant($tenant, function () use (
            $fireIdentity,
            $startKey,
            $definitions,
            $drafts,
            $contacts,
            $assistants,
            $currentAssistant,
            $engine,
            $guard,
            $sessions,
            $channels,
            $collision,
            $terminator,
            $admission,
            $logger,
        ): void {
            if (null !== $sessions->findByStartKey($startKey)) {
                $logger->info('flow.event.start_duplicate', $this->logContext($startKey));

                return;
            }

            $definition = $definitions->findLatestActiveByFlowId($this->flowId);

            if (null === $definition) {
                $logger->warning('flow.event.start_skipped_no_active_flow', [
                    'tenant_id'  => $this->tenantId,
                    'flow_id'    => $this->flowId,
                    'event_name' => $this->eventName,
                ]);

                return;
            }

            if ($drafts->isSwitchedOff($this->flowId)) {
                $logger->info('flow.event.start_skipped_flow_off', [
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

            // Outside the routing pipeline: claim the contact's session lock so the start cannot race an inbound
            // message, the emitting conversation or a second job for the same contact and assistant.
            $guard->run(
                tenantId: (string) $contact->tenant_id,
                contactId: (string) $contact->getKey(),
                assistantId: $this->assistantId,
                callback: function () use ($fireIdentity, $startKey, $definition, $contact, $sessions, $channels, $collision, $terminator, $admission, $engine, $logger): void {
                    if (null !== $sessions->findByStartKey($startKey)) {
                        $logger->info('flow.event.start_duplicate', $this->logContext($startKey));

                        return;
                    }

                    $thread = $channels->deliveryThread($this->tenantId, (string) $contact->getKey(), $this->assistantId);

                    if (null === $thread) {
                        $logger->info('flow.event.start_skipped_no_channel', $this->logContext($startKey));

                        return;
                    }

                    $verdict = $collision->assess(
                        $thread,
                        ContactFlowStartCollision::DEFAULT_BUSY_WINDOW_MINUTES,
                        $this->sourceSessionId ?? null,
                    );

                    if ($verdict->isBusy()) {
                        $logger->info('flow.event.start_skipped_busy', [...$this->logContext($startKey), 'reason' => $verdict->busyReason]);

                        return;
                    }

                    if (! $this->admitted($admission, $fireIdentity)) {
                        return;
                    }

                    foreach ($verdict->stale as $stale) {
                        $terminator->terminate($stale);
                    }

                    try {
                        $session = $engine->start(
                            $definition,
                            $contact,
                            ['flow' => ['event' => $this->payload]],
                            FlowRunOrigin::conversation(chainDepth: $this->chainDepth ?? 1, startKey: $startKey),
                        );
                    } catch (UniqueConstraintViolationException $exception) {
                        // A duplicate of this start won the race past the lock (its TTL lapsed): it started once.
                        if (null !== $sessions->findByStartKey($startKey)) {
                            return;
                        }

                        throw $exception;
                    }

                    $logger->info('flow.event.started', [
                        ...$this->logContext($startKey),
                        'session_id'  => (string) $session->getKey(),
                        'ended_stale' => count($verdict->stale),
                    ]);
                },
            );
        });
    }

    /**
     * Whether run admission lets this start through; always for a job queued before it carried its trigger.
     */
    private function admitted(TriggerRunAdmission $admission, string $fireIdentity): bool
    {
        $triggerId = $this->triggerId ?? null;

        if (null === $triggerId || '' === $triggerId) {
            return true;
        }

        return $admission->admit(new TriggerAdmissionRequest(
            tenantId: $this->tenantId,
            flowId: $this->flowId,
            triggerId: $triggerId,
            triggerType: FlowTriggerType::Event->value,
            unitKey: TriggerAdmissionRequest::unitKeyFor($triggerId, $fireIdentity),
            occurredAt: CarbonImmutable::now(),
        ))->isAllowed();
    }

    /**
     * The once-only key of this start: the trigger and what fired it, hashed to fit `flow_sessions.start_key`. Never
     * the shape of a `start_flow` key (`<execution key>:<node id>` or `sha256:…`), so the two cannot collide.
     */
    private function startKey(string $fireIdentity): string
    {
        return 'event:' . hash('sha256', ($this->triggerId ?? '') . ':' . $fireIdentity);
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(string $startKey): array
    {
        return [
            'tenant_id'  => $this->tenantId,
            'flow_id'    => $this->flowId,
            'contact_id' => $this->contactId,
            'event_name' => $this->eventName,
            'start_key'  => $startKey,
        ];
    }

    /**
     * What fired this start, the same on every retry: the event id, else this job's own id.
     */
    private function fireIdentity(): string
    {
        $eventId = $this->eventId ?? null;

        if (null !== $eventId && '' !== $eventId) {
            return 'event:' . $eventId;
        }

        return 'job:' . ($this->job?->uuid() ?? (string) Str::ulid());
    }
}
