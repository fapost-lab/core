<?php

declare(strict_types=1);

namespace App\Domains\Contact\Models;

use App\Domains\Assistant\Models\Channel;
use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                          $id
 * @property string                          $contact_id
 * @property string                          $channel_id
 * @property \Illuminate\Support\Carbon|null $last_interaction_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static Builder<static>|ChannelContact query()
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
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
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
