<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Assistant\Contracts\AssistantRepositoryInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Services\ContactChannelProbe;
use App\Domains\Flow\Services\ContactFlowStartCollision;
use App\Domains\Flow\Services\FlowSessionTerminator;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Starts the flow a `start_flow` node asked for, as a conversation of the contact it named, with the bot of the
 * target flow (its draft's assistant — the asking run may have none).
 *
 * Everything the node checked is checked again here, under the contact's session lock, and this answer holds: the
 * target still has an active definition, the contact still has a channel on an active channel of the target's
 * assistant, and the contact is not busy ({@see ContactFlowStartCollision}). Stale live conversations are ended before
 * the new one starts. A start key already used — a repeated step, a retried job, a racing duplicate caught by the
 * unique `start_key` — starts nothing. None of these outcomes is an exception: repeating would not change them.
 *
 * Like an event start, the target's access policy (public/auth) is not applied: the author wired the node explicitly.
 */
final class StartFlowForContactJob implements ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;

    /**
     * @param  array<string, mixed>  $input  the started conversation's `flow.*` variables
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $flowId,
        public readonly string $contactId,
        public readonly array $input,
        public readonly string $startKey,
        public readonly int $chainDepth,
        public readonly int $busyWindowMinutes,
        public readonly ?string $askingSessionId = null,
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
        FlowSessionRepositoryInterface $sessions,
        ContactServiceInterface $contacts,
        AssistantRepositoryInterface $assistants,
        CurrentAssistantInterface $currentAssistant,
        ContactChannelProbe $channels,
        ContactFlowStartCollision $collision,
        FlowSessionTerminator $terminator,
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
            $drafts,
            $sessions,
            $contacts,
            $assistants,
            $currentAssistant,
            $channels,
            $collision,
            $terminator,
            $engine,
            $guard,
            $logger,
        ): void {
            if (null !== $sessions->findByStartKey($this->startKey)) {
                return;
            }

            $definition = $definitions->findLatestActiveByFlowId($this->flowId);

            try {
                $assistantId = null !== $definition ? (string) $drafts->findByFlowId($this->flowId)->assistant_id : '';
                $assistant   = '' !== $assistantId ? $assistants->findById($assistantId) : null;
            } catch (Throwable) {
                $assistant = null;
            }

            if (null === $definition || null === $assistant) {
                $logger->warning('flow.start_for_contact.target_inactive', $this->logContext());

                return;
            }

            try {
                $contact = $contacts->findById($this->contactId);
            } catch (Throwable) {
                $contact = null;
            }

            if (! $contact instanceof Contact || (string) $contact->tenant_id !== $this->tenantId) {
                $logger->warning('flow.start_for_contact.contact_not_found', $this->logContext());

                return;
            }

            $assistantId = (string) $assistant->getKey();
            $currentAssistant->set($assistant);

            // Outside the routing pipeline: claim the contact's session lock so the start cannot race an inbound
            // message, or a second job, for the same contact and assistant.
            $guard->run(
                tenantId: $this->tenantId,
                contactId: (string) $contact->getKey(),
                assistantId: $assistantId,
                callback: function () use ($definition, $contact, $assistantId, $sessions, $channels, $collision, $terminator, $engine, $logger): void {
                    if (null !== $sessions->findByStartKey($this->startKey)) {
                        return;
                    }

                    $thread = $channels->deliveryThread($this->tenantId, (string) $contact->getKey(), $assistantId);

                    if (null === $thread) {
                        $logger->info('flow.start_for_contact.no_channel', $this->logContext());

                        return;
                    }

                    $verdict = $collision->assess($thread, $this->busyWindowMinutes, $this->askingSessionId);

                    if ($verdict->isBusy()) {
                        $logger->info('flow.start_for_contact.busy', [...$this->logContext(), 'reason' => $verdict->busyReason]);

                        return;
                    }

                    foreach ($verdict->stale as $stale) {
                        $terminator->terminate($stale);
                    }

                    try {
                        $session = $engine->start(
                            $definition,
                            $contact,
                            ['flow' => $this->input],
                            FlowRunOrigin::conversation(chainDepth: $this->chainDepth, startKey: $this->startKey),
                        );
                    } catch (UniqueConstraintViolationException $exception) {
                        // A duplicate of this start won the race past the lock (its TTL lapsed): it started once.
                        if (null !== $sessions->findByStartKey($this->startKey)) {
                            return;
                        }

                        throw $exception;
                    }

                    $logger->info('flow.start_for_contact.started', [
                        ...$this->logContext(),
                        'session_id'  => (string) $session->getKey(),
                        'ended_stale' => count($verdict->stale),
                    ]);
                },
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(): array
    {
        return [
            'tenant_id'  => $this->tenantId,
            'flow_id'    => $this->flowId,
            'contact_id' => $this->contactId,
            'start_key'  => $this->startKey,
        ];
    }
}
