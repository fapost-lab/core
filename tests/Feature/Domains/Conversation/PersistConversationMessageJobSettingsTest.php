<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Conversation\Contracts\ConversationStoreInterface;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use App\Domains\Conversation\Jobs\FetchConversationMediaJob;
use App\Domains\Conversation\Jobs\PersistConversationMessageJob;
use App\Domains\Tenancy\Settings\TenantSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\Feature\FeatureTestCase;

/**
 * The persist job decides inside the entry's own tenant: a tenant with its log off writes nothing, and one with
 * media download off queues no fetch.
 */
final class PersistConversationMessageJobSettingsTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_nothing_is_written_when_the_tenant_has_logging_off(): void
    {
        Bus::fake();
        $this->store(logging: false, media: true);

        $store = Mockery::mock(ConversationStoreInterface::class);
        $store->shouldNotReceive('ensureConversation');
        $store->shouldNotReceive('appendMessage');
        $this->app->instance(ConversationStoreInterface::class, $store);

        $this->app->call([new PersistConversationMessageJob($this->entry()), 'handle']);

        Bus::assertNotDispatched(FetchConversationMediaJob::class);
    }

    public function test_the_message_is_written_when_the_tenant_has_logging_on_even_if_the_install_default_is_off(): void
    {
        Bus::fake();
        config(['conversation.enabled' => false]);
        $this->store(logging: true, media: true);

        $store = Mockery::mock(ConversationStoreInterface::class);
        $store->shouldReceive('ensureConversation')->once()->andReturn('conv-1');
        $store->shouldReceive('appendMessage')->once()->andReturn('msg-1');
        $this->app->instance(ConversationStoreInterface::class, $store);

        $this->app->call([new PersistConversationMessageJob($this->entry()), 'handle']);

        Bus::assertDispatched(FetchConversationMediaJob::class);
    }

    public function test_no_media_fetch_is_queued_when_the_tenant_has_media_download_off(): void
    {
        Bus::fake();
        config(['conversation.media.fetch' => true]);
        $this->store(logging: true, media: false);

        $store = Mockery::mock(ConversationStoreInterface::class);
        $store->shouldReceive('ensureConversation')->once()->andReturn('conv-1');
        $store->shouldReceive('appendMessage')->once()->andReturn('msg-1');
        $this->app->instance(ConversationStoreInterface::class, $store);

        $this->app->call([new PersistConversationMessageJob($this->entry()), 'handle']);

        Bus::assertNotDispatched(FetchConversationMediaJob::class);
    }

    private function store(bool $logging, bool $media): void
    {
        $settings                           = new TenantSettings();
        $settings->conversation_logging     = $logging;
        $settings->conversation_media_fetch = $media;
        $settings->save();
        $this->app->forgetScopedInstances();
    }

    private function entry(): MessageLogEntry
    {
        return new MessageLogEntry(
            tenantId: self::TENANT_ID,
            assistantId: 'assistant-1',
            contactId: 'contact-1',
            channelId: 'channel-1',
            platform: 'telegram',
            direction: MessageDirection::Inbound,
            senderType: MessageSenderType::Contact,
            contentType: MessageContentType::Photo,
            text: null,
            payload: [],
            media: [['provider_file_id' => 'AgAC', 'kind' => 'photo', 'status' => 'pending']],
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
