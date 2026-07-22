<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Models;

use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Enums\ConversationStatus;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Conversation thread aggregate — one row per (tenant, assistant, contact, channel).
 * Denormalized fields (last_message_at, preview, counters) feed the future Inbox
 * feed without scanning messages. Postgres-driver only; read access must go through
 * a reader port, never Eloquent relations across the driver boundary (spec §2, §10).
 *
 * @property string                  $id
 * @property string                  $tenant_id
 * @property string                  $assistant_id
 * @property string                  $contact_id
 * @property string                  $channel_id
 * @property string                  $platform
 * @property ConversationStatus      $status
 * @property string|null             $owner_type
 * @property string|null             $owner_staff_user_id
 * @property Carbon|null             $last_message_at
 * @property Carbon|null             $last_inbound_at
 * @property Carbon|null             $last_outbound_at
 * @property string|null             $last_message_preview
 * @property int                     $unread_count
 * @property int                     $message_count
 * @property array<string, mixed>    $meta
 * @property Carbon                  $created_at
 * @property Carbon                  $updated_at
 */
final class Conversation extends BaseModel
{
    use HasUlidPrimaryKey;

    protected $table = 'conversations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'assistant_id',
        'contact_id',
        'channel_id',
        'platform',
        'status',
        'owner_type',
        'owner_staff_user_id',
        'last_message_at',
        'last_inbound_at',
        'last_outbound_at',
        'last_message_preview',
        'unread_count',
        'message_count',
        'meta',
    ];

    /**
     * Thread → Contact. Aggregate-level relation used only by the read surface
     * (Filament). The driver-boundary rule (spec §10) forbids Eloquent relations
     * on the *messages*, which are the ClickHouse-swappable part — the aggregate
     * itself is always Postgres, so this relation is safe.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'           => ConversationStatus::class,
            'last_message_at'  => 'datetime',
            'last_inbound_at'  => 'datetime',
            'last_outbound_at' => 'datetime',
            'unread_count'     => 'integer',
            'message_count'    => 'integer',
            'meta'             => 'array',
        ];
    }
}
