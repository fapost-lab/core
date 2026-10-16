<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Jobs;

use App\Domains\Conversation\Contracts\ConversationStoreInterface;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Live\ConversationActivityNotifier;
use App\Domains\Conversation\Settings\ConversationSettings;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Persists one transcript message into the configured store driver. Runs on the
 * low-priority `messaging.logging` queue so log volume (broadcast especially)
 * never competes with transactional traffic. Idempotent by design — the store
 * collapses duplicates, so retries are safe.
 */
final class PersistConversationMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly MessageLogEntry $entry,
    ) {
        $this->onQueue('messaging.logging');
    }

    public function handle(
        ConversationStoreInterface $store,
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        ConversationActivityNotifier $activity,
    ): void {
        $tenant = $tenants->getById($this->entry->tenantId);

        $switcher->runForTenant($tenant, function () use ($store, $activity): void {
            // Resolved inside the switch, in the entry's own tenant: a tenant that has turned its log off after the
            // entry was queued writes nothing. The memo is per tenant id, so a scoped instance is safe either way.
            $settings = app(ConversationSettings::class);

            if (! $settings->loggingEnabled()) {
                return;
            }

            $conversationId = $store->ensureConversation($this->entry->ref());
            $messageId      = $store->appendMessage($conversationId, $this->entry);

            if (null !== $messageId) {
                $this->fetchMediaIfNeeded($messageId, $settings);

                // An open inbox shows the new message (and the unread count) without waiting for its next poll.
                $activity->touched($this->entry->tenantId, $this->entry->assistantId);
            }
        });
    }

    /**
     * Kick off async media ingestion for inbound attachments still pending a
     * download (spec §6.1). Only inbound media is fetched — outbound already
     * references a stored media_file.
     */
    private function fetchMediaIfNeeded(string $messageId, ConversationSettings $settings): void
    {
        if (! $settings->mediaFetchEnabled()) {
            return;
        }

        if (MessageDirection::Inbound !== $this->entry->direction) {
            return;
        }

        $pending = array_filter(
            $this->entry->media,
            static fn (array $descriptor): bool => 'pending' === ($descriptor['status'] ?? null),
        );

        if ([] === $pending) {
            return;
        }

        FetchConversationMediaJob::dispatch(
            $this->entry->tenantId,
            $messageId,
            $this->entry->channelId,
            $this->entry->media,
        );
    }
}
