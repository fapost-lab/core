<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Jobs;

use App\Domains\Conversation\Contracts\ConversationStoreInterface;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\MessageDirection;
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
    ): void {
        $tenant = $tenants->getById($this->entry->tenantId);

        $switcher->runForTenant($tenant, function () use ($store): void {
            $conversationId = $store->ensureConversation($this->entry->ref());
            $messageId      = $store->appendMessage($conversationId, $this->entry);

            if (null !== $messageId) {
                $this->fetchMediaIfNeeded($messageId);
            }
        });
    }

    /**
     * Kick off async media ingestion for inbound attachments still pending a
     * download (spec §6.1). Only inbound media is fetched — outbound already
     * references a stored media_file.
     */
    private function fetchMediaIfNeeded(string $messageId): void
    {
        if (! (bool) config('conversation.media.fetch', true)) {
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
