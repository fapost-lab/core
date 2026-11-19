<?php

declare(strict_types=1);

namespace App\Domains\Flow\Listeners;

use App\Domains\Broadcasting\Events\BroadcastCompleted;
use App\Domains\Contact\Events\ContactCreated;
use App\Domains\Contact\Events\ContactTagAdded;
use App\Domains\Contact\Events\ContactTagRemoved;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Conversation\Events\OperatorRequestStateChanged;
use App\Domains\Flow\Contracts\FlowTriggerEventPublisherInterface;
use App\Domains\Flow\Events\CoreEventCatalog;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * Flow's side of the Contact / Broadcasting / Conversation → Flow seams: a Core event happened, so it is published to
 * the tenant's event triggers under its {@see CoreEventCatalog} name.
 *
 * Runs synchronously inside whatever raised the event (a webhook intake, an admin action, a broadcast job), so the
 * flow engine's chain-depth context is still set; the publisher itself queues the job that starts the runs. A failure
 * is reported, never thrown into that caller, whose own change has already happened. Known limit: inside the caller's
 * open transaction on PostgreSQL, a failed query of the publisher (the "does any trigger listen" read) still aborts
 * that transaction — the exception is swallowed, the transaction is not saved. Payloads carry identifiers and short
 * fields only, never a contact's personal data.
 */
final readonly class PublishCoreEvents
{
    public function __construct(
        private FlowTriggerEventPublisherInterface $publisher,
        private ExceptionHandler $exceptions,
    ) {
    }

    public function contactCreated(ContactCreated $event): void
    {
        $this->publish($event->tenantId, CoreEventCatalog::CONTACT_CREATED, [
            'contact_id' => $event->contactId,
            'platform'   => $event->platform,
            'language'   => $event->language,
        ]);
    }

    public function contactTagAdded(ContactTagAdded $event): void
    {
        $this->publish($event->tenantId, CoreEventCatalog::CONTACT_TAG_ADDED, [
            'contact_id' => $event->contactId,
            'tag'        => $event->tag,
            'tagged_by'  => $event->taggedBy,
        ]);
    }

    public function contactTagRemoved(ContactTagRemoved $event): void
    {
        $this->publish($event->tenantId, CoreEventCatalog::CONTACT_TAG_REMOVED, [
            'contact_id' => $event->contactId,
            'tag'        => $event->tag,
        ]);
    }

    public function broadcastCompleted(BroadcastCompleted $event): void
    {
        $this->publish($event->tenantId, CoreEventCatalog::BROADCAST_COMPLETED, [
            'broadcast_id'     => $event->broadcastId,
            'name'             => $event->name,
            'recipients_total' => $event->recipientsTotal,
            'sent'             => $event->sent,
            'failed'           => $event->failed,
        ]);
    }

    /**
     * Only the timeout is an event trigger: an accept, a finish or a contact's cancel is not.
     */
    public function operatorRequestStateChanged(OperatorRequestStateChanged $event): void
    {
        if (OperatorRequestState::Timeout !== $event->state) {
            return;
        }

        $this->publish($event->tenantId, CoreEventCatalog::OPERATOR_REQUEST_TIMED_OUT, [
            'request_id'   => $event->requestId,
            'contact_id'   => $event->contactId,
            'assistant_id' => $event->assistantId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publish(string $tenantId, string $eventName, array $payload): void
    {
        try {
            $this->publisher->publish($tenantId, $eventName, $payload, ['origin' => 'core']);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }
}
