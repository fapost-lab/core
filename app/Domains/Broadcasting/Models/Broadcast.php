<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Models;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Broadcast aggregate — a one-off message fan-out to a targeted audience of an
 * assistant. Owns lifecycle state and denormalized delivery counters; the actual
 * per-recipient delivery lives in {@see BroadcastRecipient}.
 *
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string                          $assistant_id
 * @property string                          $name
 * @property array<string, string>|null      $message
 * @property BroadcastTarget                 $target_type
 * @property list<string>|null               $target_tags
 * @property string|null                     $target_segment_id
 * @property BroadcastStatus                 $status
 * @property int                             $total_recipients
 * @property int                             $sent_count
 * @property int                             $failed_count
 * @property int                             $skipped_count
 * @property string|null                     $created_by
 * @property Carbon|null                     $started_at
 * @property Carbon|null                     $completed_at
 * @property-read Assistant                  $assistant
 */
final class Broadcast extends BaseModel
{
    use HasUlidPrimaryKey;

    protected $table = 'broadcasts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'assistant_id',
        'name',
        'message',
        'target_type',
        'target_tags',
        'target_segment_id',
        'status',
        'total_recipients',
        'sent_count',
        'failed_count',
        'skipped_count',
        'created_by',
        'started_at',
        'completed_at',
    ];

    /**
     * @return BelongsTo<Assistant, $this>
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    /**
     * @return HasMany<BroadcastRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => BroadcastTarget::class,
            'target_tags' => 'array',
            // JSONB locale map `{lang: text}`, resolved at send time via
            // {@see \App\Domains\Flow\Contracts\ContentTranslatorInterface::resolveField}.
            'message'          => 'array',
            'status'           => BroadcastStatus::class,
            'total_recipients' => 'integer',
            'sent_count'       => 'integer',
            'failed_count'     => 'integer',
            'skipped_count'    => 'integer',
            'started_at'       => 'datetime',
            'completed_at'     => 'datetime',
        ];
    }
}
