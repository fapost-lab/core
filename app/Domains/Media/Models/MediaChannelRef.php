<?php

declare(strict_types=1);

namespace App\Domains\Media\Models;

use App\Domains\Channels\Models\Channel;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cached provider-side file id for a (blob, channel) pair.
 *
 * Created on the first send through a channel, reused on every subsequent send. When
 * `expires_at` lapses (WhatsApp 30-day TTL) the dispatcher re-uploads.
 *
 * @property string                       $id
 * @property string                       $blob_id
 * @property string                       $channel_id
 * @property string                       $provider_file_id
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Carbon\CarbonImmutable      $uploaded_at
 * @property-read MediaBlob               $blob
 * @property-read Channel                 $channel
 */
final class MediaChannelRef extends BaseModel
{
    use HasUlidPrimaryKey;

    public $timestamps = false;

    protected $table = 'media_channel_refs';

    /** @var list<string> */
    protected $fillable = [
        'blob_id',
        'channel_id',
        'provider_file_id',
        'expires_at',
        'uploaded_at',
    ];

    /**
     * @return BelongsTo<MediaBlob, $this>
     */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(MediaBlob::class, 'blob_id');
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at'  => 'immutable_datetime',
            'uploaded_at' => 'immutable_datetime',
        ];
    }
}
