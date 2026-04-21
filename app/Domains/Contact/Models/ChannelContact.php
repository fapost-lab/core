<?php

declare(strict_types=1);

namespace App\Domains\Contact\Models;

use App\Domains\Channels\Models\Channel;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact's per-channel linkage.
 *
 * Represents a unique (contact_id, channel_id) pairing and stores last interaction timestamp.
 *
 * @property-read Channel|null $channel
 * @property-read Contact|null $contact
 * @method static Builder<static>|ChannelContact newModelQuery()
 * @method static Builder<static>|ChannelContact newQuery()
 * @method static Builder<static>|ChannelContact query()
 * @property string $id
 * @property string $contact_id
 * @property string $channel_id
 * @property \Illuminate\Support\Carbon|null $last_interaction_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static Builder<static>|ChannelContact whereChannelId($value)
 * @method static Builder<static>|ChannelContact whereContactId($value)
 * @method static Builder<static>|ChannelContact whereCreatedAt($value)
 * @method static Builder<static>|ChannelContact whereId($value)
 * @method static Builder<static>|ChannelContact whereLastInteractionAt($value)
 * @method static Builder<static>|ChannelContact whereUpdatedAt($value)
 * @mixin \Eloquent
 */
final class ChannelContact extends Model
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'channel_id',
        'last_interaction_at',
    ];

    /**
     * Relationship: contact owning this linkage.
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Relationship: assistant channel associated with this linkage.
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_interaction_at' => 'datetime',
        ];
    }
}
