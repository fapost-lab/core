<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Models;

use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * Read-only Eloquent surface over conversation_messages (Postgres driver). Writes
 * go through {@see \App\Domains\Conversation\Store\Postgres\PostgresConversationStore}
 * via raw inserts (partitioned table, composite PK); this model exists for the
 * Postgres reader / Filament transcript only. The table is partitioned by month
 * with PK (id, created_at), so there is no updated_at.
 *
 * @property string                  $id
 * @property string                  $tenant_id
 * @property string                  $conversation_id
 * @property string                  $contact_id
 * @property string                  $assistant_id
 * @property string                  $channel_id
 * @property MessageDirection        $direction
 * @property MessageSenderType       $sender_type
 * @property string|null             $sender_staff_user_id
 * @property MessageContentType      $content_type
 * @property string|null             $text
 * @property array<string, mixed>|null $payload
 * @property list<array<string, mixed>>|null $media
 * @property string|null             $provider_message_id
 * @property string|null             $reply_to_provider_message_id
 * @property DeliveryStatus          $status
 * @property array<string, mixed>|null $error
 * @property MessageOrigin           $origin
 * @property array<string, mixed>|null $origin_ref
 * @property string|null             $idempotency_key
 * @property Carbon                  $created_at
 */
final class ConversationMessage extends BaseModel
{
    use HasUlidPrimaryKey;

    public $timestamps = false;

    protected $table = 'conversation_messages';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction'    => MessageDirection::class,
            'sender_type'  => MessageSenderType::class,
            'content_type' => MessageContentType::class,
            'status'       => DeliveryStatus::class,
            'origin'       => MessageOrigin::class,
            'payload'      => 'array',
            'media'        => 'array',
            'error'        => 'array',
            'origin_ref'   => 'array',
            'created_at'   => 'datetime',
        ];
    }
}
