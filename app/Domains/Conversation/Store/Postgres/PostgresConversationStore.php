<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Store\Postgres;

use App\Domains\Conversation\Contracts\ConversationStoreInterface;
use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\ConversationStatus;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Models\Conversation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Default (Postgres) driver for the conversation transcript. Threads live in the
 * `conversations` aggregate; messages in the monthly-partitioned
 * `conversation_messages`. Writes are idempotent so retried jobs and duplicate
 * deliveries collapse (spec §5, §8).
 */
final readonly class PostgresConversationStore implements ConversationStoreInterface
{
    private const int PREVIEW_LENGTH = 160;

    public function __construct(
        private ConversationPartitionManager $partitions,
    ) {
    }

    public function ensureConversation(ConversationRef $ref): string
    {
        // createOrFirst (insert-first, catch unique violation) is race-safe against
        // conversations_thread_unique under concurrent first messages for the same
        // thread — firstOrCreate would let both workers miss and one throw.
        $conversation = Conversation::query()->createOrFirst(
            [
                'tenant_id'    => $ref->tenantId,
                'assistant_id' => $ref->assistantId,
                'contact_id'   => $ref->contactId,
                'channel_id'   => $ref->channelId,
            ],
            [
                'platform' => $ref->platform,
                'status'   => ConversationStatus::Open->value,
                'meta'     => [],
            ],
        );

        return (string) $conversation->getKey();
    }

    public function appendMessage(string $conversationId, MessageLogEntry $entry): ?string
    {
        // Retry-safe dedup independent of timestamp drift: the unique index also
        // carries created_at (partition-key requirement), so a retry that rebuilds
        // the entry with a fresh occurredAt would slip past it. An explicit check
        // on the stable (conversation_id, direction, idempotency_key) triple closes
        // that gap for every capture path, not just those with a stable timestamp.
        if (null !== $entry->idempotencyKey && $this->alreadyRecorded($conversationId, $entry)) {
            return null;
        }

        $occurredAt = CarbonImmutable::instance(Carbon::instance($entry->occurredAt))->utc();
        $this->partitions->ensureMonthlyPartition($occurredAt);

        $messageId = (string) Str::ulid()->toRfc4122();

        $inserted = DB::table('conversation_messages')->insertOrIgnore([
            'id'                           => $messageId,
            'tenant_id'                    => $entry->tenantId,
            'conversation_id'              => $conversationId,
            'contact_id'                   => $entry->contactId,
            'assistant_id'                 => $entry->assistantId,
            'channel_id'                   => $entry->channelId,
            'direction'                    => $entry->direction->value,
            'sender_type'                  => $entry->senderType->value,
            'sender_staff_user_id'         => $entry->senderStaffUserId,
            'content_type'                 => $entry->contentType->value,
            'text'                         => $entry->text,
            'payload'                      => $this->encode($entry->payload),
            'media'                        => $this->encode($entry->media),
            'provider_message_id'          => $entry->providerMessageId,
            'reply_to_provider_message_id' => $entry->replyToProviderMessageId,
            'status'                       => $entry->status->value,
            'error'                        => null,
            'origin'                       => $entry->origin->value,
            'origin_ref'                   => $this->encode($entry->originRef),
            'idempotency_key'              => $entry->idempotencyKey,
            'created_at'                   => $occurredAt->format('Y-m-d H:i:sP'),
        ]);

        // A duplicate (unique idempotency collision) inserts 0 rows — do not
        // double-count the aggregate for a message we already recorded.
        if ($inserted < 1) {
            return null;
        }

        $this->bumpAggregate($conversationId, $entry, $occurredAt);

        return $messageId;
    }

    public function updateMessageMedia(string $messageId, array $media): void
    {
        DB::table('conversation_messages')
            ->where('id', $messageId)
            ->update(['media' => $this->encode($media)]);
    }

    public function updateStatus(string $providerMessageId, DeliveryStatus $status): void
    {
        DB::table('conversation_messages')
            ->where('provider_message_id', $providerMessageId)
            ->update(['status' => $status->value]);
    }

    private function alreadyRecorded(string $conversationId, MessageLogEntry $entry): bool
    {
        return DB::table('conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('direction', $entry->direction->value)
            ->where('idempotency_key', $entry->idempotencyKey)
            ->exists();
    }

    private function bumpAggregate(string $conversationId, MessageLogEntry $entry, CarbonImmutable $occurredAt): void
    {
        $timestamp = $occurredAt->format('Y-m-d H:i:sP');

        $values = [
            'last_message_at'      => $timestamp,
            'last_message_preview' => $this->preview($entry),
            'message_count'        => DB::raw('message_count + 1'),
            'updated_at'           => Carbon::now(),
        ];

        if (MessageDirection::Inbound === $entry->direction) {
            $values['last_inbound_at'] = $timestamp;
            $values['unread_count']    = DB::raw('unread_count + 1');
        } else {
            $values['last_outbound_at'] = $timestamp;
        }

        DB::table('conversations')->where('id', $conversationId)->update($values);
    }

    private function preview(MessageLogEntry $entry): string
    {
        $text = $entry->text ?? "[{$entry->contentType->value}]";

        return Str::limit($text, self::PREVIEW_LENGTH, '');
    }

    /**
     * @param  array<int|string, mixed>  $value
     */
    private function encode(array $value): ?string
    {
        return [] === $value ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
