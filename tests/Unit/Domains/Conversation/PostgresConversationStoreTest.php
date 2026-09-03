<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Conversation;

use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use App\Domains\Conversation\Store\Postgres\ConversationPartitionManager;
use App\Domains\Conversation\Store\Postgres\PostgresConversationStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PostgresConversationStoreTest extends TestCase
{
    use DatabaseTransactions;

    private PostgresConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');

        Schema::create('conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('assistant_id');
            $table->uuid('contact_id');
            $table->uuid('channel_id');
            $table->string('platform', 32);
            $table->string('status', 16)->default('open');
            $table->string('owner_type', 16)->nullable();
            $table->uuid('owner_staff_user_id')->nullable();
            $table->timestampTz('last_message_at')->nullable();
            $table->timestampTz('last_inbound_at')->nullable();
            $table->timestampTz('last_outbound_at')->nullable();
            $table->string('last_message_preview')->nullable();
            $table->integer('unread_count')->default(0);
            $table->bigInteger('message_count')->default(0);
            $table->jsonb('meta')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'assistant_id', 'contact_id', 'channel_id'], 'conversations_thread_unique');
        });

        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            $this->createPartitionedConversationMessages();
        } else {
            Schema::create('conversation_messages', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id');
                $table->uuid('conversation_id');
                $table->uuid('contact_id');
                $table->uuid('assistant_id');
                $table->uuid('channel_id');
                $table->string('direction', 16);
                $table->string('sender_type', 16);
                $table->uuid('sender_staff_user_id')->nullable();
                $table->string('content_type', 32);
                $table->text('text')->nullable();
                $table->jsonb('payload')->nullable();
                $table->jsonb('media')->nullable();
                $table->string('provider_message_id')->nullable();
                $table->string('reply_to_provider_message_id')->nullable();
                $table->string('status', 16);
                $table->jsonb('error')->nullable();
                $table->string('origin', 16);
                $table->jsonb('origin_ref')->nullable();
                $table->string('idempotency_key')->nullable();
                $table->timestampTz('created_at');
                $table->unique(['conversation_id', 'direction', 'idempotency_key', 'created_at'], 'conversation_messages_idem_unique');
            });
        }

        $this->store = new PostgresConversationStore(new ConversationPartitionManager());
    }

    /**
     * Mirrors database/migrations/tenant/2026_06_12_000002_create_conversation_messages_table.php's
     * pgsql branch: {@see ConversationPartitionManager} runs `CREATE TABLE ... PARTITION OF
     * conversation_messages`, which fails against a plain (non-partitioned) table.
     */
    private function createPartitionedConversationMessages(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE conversation_messages (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    conversation_id uuid NOT NULL,
    contact_id uuid NOT NULL,
    assistant_id uuid NOT NULL,
    channel_id uuid NOT NULL,
    direction varchar(16) NOT NULL,
    sender_type varchar(16) NOT NULL,
    sender_staff_user_id uuid NULL,
    content_type varchar(32) NOT NULL,
    text text NULL,
    payload jsonb NULL,
    media jsonb NULL,
    provider_message_id varchar(255) NULL,
    reply_to_provider_message_id varchar(255) NULL,
    status varchar(16) NOT NULL,
    error jsonb NULL,
    origin varchar(16) NOT NULL,
    origin_ref jsonb NULL,
    idempotency_key varchar(255) NULL,
    created_at timestamp with time zone NOT NULL,
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at)
SQL);

        DB::statement('CREATE UNIQUE INDEX conversation_messages_idem_unique ON conversation_messages (conversation_id, direction, idempotency_key, created_at)');

        $start = CarbonImmutable::now('UTC')->startOfMonth();
        for ($i = 0; $i < 3; $i++) {
            $month      = $start->addMonths($i);
            $monthStart = $month->startOfMonth()->format('Y-m-d H:i:sP');
            $monthEnd   = $month->addMonth()->startOfMonth()->format('Y-m-d H:i:sP');
            $suffix     = $month->format('Y_m');
            DB::statement("CREATE TABLE IF NOT EXISTS conversation_messages_{$suffix} PARTITION OF conversation_messages FOR VALUES FROM ('{$monthStart}') TO ('{$monthEnd}')");
        }
    }

    public function test_ensure_conversation_is_idempotent_by_ref(): void
    {
        $ref = $this->ref();

        $first  = $this->store->ensureConversation($ref);
        $second = $this->store->ensureConversation($ref);

        $this->assertSame($first, $second);
        $this->assertSame(1, DB::table('conversations')->count());
    }

    public function test_append_message_inserts_row_and_bumps_aggregate(): void
    {
        $conversationId = $this->store->ensureConversation($this->ref());

        $messageId = $this->store->appendMessage($conversationId, $this->inbound('update-1', 'Hello'));

        $this->assertNotNull($messageId);
        $this->assertSame(1, DB::table('conversation_messages')->count());

        $conversation = DB::table('conversations')->where('id', $conversationId)->first();
        $this->assertSame(1, (int) $conversation->message_count);
        $this->assertSame(1, (int) $conversation->unread_count);
        $this->assertSame('Hello', $conversation->last_message_preview);
        $this->assertNotNull($conversation->last_message_at);
        $this->assertNotNull($conversation->last_inbound_at);
        $this->assertNull($conversation->last_outbound_at);
    }

    public function test_append_message_is_idempotent_by_idempotency_key(): void
    {
        $conversationId = $this->store->ensureConversation($this->ref());
        $entry          = $this->inbound('update-42', 'Twice');

        $first  = $this->store->appendMessage($conversationId, $entry);
        $second = $this->store->appendMessage($conversationId, $entry);

        $this->assertNotNull($first);
        $this->assertNull($second, 'Duplicate append must return null.');
        $this->assertSame(1, DB::table('conversation_messages')->count());

        // Aggregate must not double-count the duplicate.
        $conversation = DB::table('conversations')->where('id', $conversationId)->first();
        $this->assertSame(1, (int) $conversation->message_count);
        $this->assertSame(1, (int) $conversation->unread_count);
    }

    public function test_outbound_message_updates_outbound_aggregate_only(): void
    {
        $conversationId = $this->store->ensureConversation($this->ref());

        $this->store->appendMessage($conversationId, $this->outbound('out-1', 'Reply'));

        $conversation = DB::table('conversations')->where('id', $conversationId)->first();
        $this->assertSame(0, (int) $conversation->unread_count);
        $this->assertNotNull($conversation->last_outbound_at);
        $this->assertNull($conversation->last_inbound_at);
    }

    public function test_update_status_sets_delivery_state_by_provider_message_id(): void
    {
        $conversationId = $this->store->ensureConversation($this->ref());
        $this->store->appendMessage($conversationId, $this->outbound('out-9', 'Sent', providerMessageId: 'pmid-9'));

        $this->store->updateStatus('pmid-9', DeliveryStatus::Read);

        $message = DB::table('conversation_messages')->where('provider_message_id', 'pmid-9')->first();
        $this->assertSame('read', $message->status);
    }

    public function test_append_is_idempotent_even_when_occurred_at_drifts_on_retry(): void
    {
        $conversationId = $this->store->ensureConversation($this->ref());

        // Same message (same idempotency_key) captured twice with DIFFERENT
        // timestamps — simulates a webhook/handler retry rebuilding the entry.
        $first  = $this->entry(MessageDirection::Inbound, MessageSenderType::Contact, DeliveryStatus::Received, 'update-drift', 'Retry', null, '2026-06-15 10:00:00');
        $second = $this->entry(MessageDirection::Inbound, MessageSenderType::Contact, DeliveryStatus::Received, 'update-drift', 'Retry', null, '2026-06-15 10:05:30');

        $this->assertNotNull($this->store->appendMessage($conversationId, $first));
        $this->assertNull($this->store->appendMessage($conversationId, $second), 'Timestamp drift must not defeat dedup.');

        $this->assertSame(1, DB::table('conversation_messages')->count());
        $conversation = DB::table('conversations')->where('id', $conversationId)->first();
        $this->assertSame(1, (int) $conversation->message_count);
    }

    public function test_update_message_media_replaces_descriptors(): void
    {
        $conversationId = $this->store->ensureConversation($this->ref());
        $messageId      = $this->store->appendMessage($conversationId, $this->inbound('update-m', 'photo'));

        $this->assertNotNull($messageId);

        $this->store->updateMessageMedia($messageId, [
            ['media_file_id' => 'mf-1', 'kind' => 'photo', 'status' => 'ready'],
        ]);

        $message = DB::table('conversation_messages')->where('id', $messageId)->first();
        $media   = json_decode((string) $message->media, true);

        $this->assertSame('mf-1', $media[0]['media_file_id']);
        $this->assertSame('ready', $media[0]['status']);
    }

    private function ref(): ConversationRef
    {
        return new ConversationRef(
            tenantId: '00000000-0000-0000-0000-0000000000a1',
            assistantId: '00000000-0000-0000-0000-0000000000b1',
            contactId: '00000000-0000-0000-0000-0000000000c1',
            channelId: '00000000-0000-0000-0000-0000000000d1',
            platform: 'telegram',
        );
    }

    private function inbound(string $idempotencyKey, string $text): MessageLogEntry
    {
        return $this->entry(MessageDirection::Inbound, MessageSenderType::Contact, DeliveryStatus::Received, $idempotencyKey, $text, null);
    }

    private function outbound(string $idempotencyKey, string $text, ?string $providerMessageId = null): MessageLogEntry
    {
        return $this->entry(MessageDirection::Outbound, MessageSenderType::Assistant, DeliveryStatus::Sent, $idempotencyKey, $text, $providerMessageId);
    }

    private function entry(
        MessageDirection $direction,
        MessageSenderType $senderType,
        DeliveryStatus $status,
        string $idempotencyKey,
        string $text,
        ?string $providerMessageId,
        string $occurredAt = '2026-06-15 10:00:00',
    ): MessageLogEntry {
        $ref = $this->ref();

        return new MessageLogEntry(
            tenantId: $ref->tenantId,
            assistantId: $ref->assistantId,
            contactId: $ref->contactId,
            channelId: $ref->channelId,
            platform: $ref->platform,
            direction: $direction,
            senderType: $senderType,
            contentType: MessageContentType::Text,
            text: $text,
            payload: [],
            media: [],
            providerMessageId: $providerMessageId,
            replyToProviderMessageId: null,
            origin: MessageOrigin::Flow,
            originRef: [],
            idempotencyKey: $idempotencyKey,
            status: $status,
            occurredAt: CarbonImmutable::parse($occurredAt, 'UTC'),
        );
    }
}
