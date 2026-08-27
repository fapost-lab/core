<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Conversation;

use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use App\Domains\Conversation\Jobs\FetchConversationMediaJob;
use App\Domains\Conversation\Jobs\PersistConversationMessageJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Covers the media-fetch dispatch decision in isolation (the tenant/store
 * machinery around it is exercised by the store tests). `fetchMediaIfNeeded`
 * is invoked directly so no tenant context is required.
 */
final class PersistConversationMessageJobTest extends TestCase
{
    public function test_dispatches_media_fetch_for_inbound_pending_media(): void
    {
        Bus::fake();

        $entry = $this->entry(MessageDirection::Inbound, [
            ['provider_file_id' => 'AgAC', 'kind' => 'photo', 'status' => 'pending'],
        ]);

        $this->fetchMedia($entry, 'msg-1');

        Bus::assertDispatched(
            FetchConversationMediaJob::class,
            static fn (FetchConversationMediaJob $job): bool => 'msg-1' === $job->messageId
                && 'channel-1' === $job->channelId
                && 'AgAC' === ($job->media[0]['provider_file_id'] ?? null),
        );
    }

    public function test_does_not_dispatch_for_outbound_media(): void
    {
        Bus::fake();

        $entry = $this->entry(MessageDirection::Outbound, [
            ['provider_file_id' => 'AgAC', 'kind' => 'photo', 'status' => 'pending'],
        ]);

        $this->fetchMedia($entry, 'msg-2');

        Bus::assertNotDispatched(FetchConversationMediaJob::class);
    }

    public function test_does_not_dispatch_when_no_pending_media(): void
    {
        Bus::fake();

        $entry = $this->entry(MessageDirection::Inbound, []);

        $this->fetchMedia($entry, 'msg-3');

        Bus::assertNotDispatched(FetchConversationMediaJob::class);
    }

    public function test_does_not_dispatch_when_media_fetch_disabled(): void
    {
        Bus::fake();
        config()->set('conversation.media.fetch', false);

        $entry = $this->entry(MessageDirection::Inbound, [
            ['provider_file_id' => 'AgAC', 'kind' => 'photo', 'status' => 'pending'],
        ]);

        $this->fetchMedia($entry, 'msg-4');

        Bus::assertNotDispatched(FetchConversationMediaJob::class);
    }

    private function fetchMedia(MessageLogEntry $entry, string $messageId): void
    {
        $job    = new PersistConversationMessageJob($entry);
        $method = new ReflectionMethod($job, 'fetchMediaIfNeeded');
        $method->invoke($job, $messageId);
    }

    /**
     * @param  list<array<string, mixed>>  $media
     */
    private function entry(MessageDirection $direction, array $media): MessageLogEntry
    {
        return new MessageLogEntry(
            tenantId: 'tenant-1',
            assistantId: 'assistant-1',
            contactId: 'contact-1',
            channelId: 'channel-1',
            platform: 'telegram',
            direction: $direction,
            senderType: MessageSenderType::Contact,
            contentType: MessageContentType::Photo,
            text: null,
            payload: [],
            media: $media,
            providerMessageId: null,
            replyToProviderMessageId: null,
            origin: MessageOrigin::Flow,
            originRef: [],
            idempotencyKey: 'update-1',
            status: DeliveryStatus::Received,
            occurredAt: CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'),
        );
    }
}
