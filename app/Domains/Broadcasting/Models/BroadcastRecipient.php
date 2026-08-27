<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Models;

use App\Domains\Broadcasting\Enums\RecipientStatus;
use App\Domains\Contact\Models\Contact;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-recipient delivery record for a broadcast fan-out. Status transitions
 * Pending → Sent | Failed | Skipped, carrying the provider message id (for the
 * transcript / delivery status) or the failure reason.
 *
 * @property string                  $id
 * @property string                  $tenant_id
 * @property string                  $broadcast_id
 * @property string                  $contact_id
 * @property string                  $channel_id
 * @property RecipientStatus         $status
 * @property string|null             $provider_message_id
 * @property string|null             $error
 * @property Carbon|null             $sent_at
 * @property-read Broadcast          $broadcast
 * @property-read Contact            $contact
 */
final class BroadcastRecipient extends BaseModel
{
    use HasUlidPrimaryKey;

    protected $table = 'broadcast_recipients';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'broadcast_id',
        'contact_id',
        'channel_id',
        'status',
        'provider_message_id',
        'error',
        'sent_at',
    ];

    /**
     * @return BelongsTo<Broadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
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
            'status'  => RecipientStatus::class,
            'sent_at' => 'datetime',
        ];
    }
}
