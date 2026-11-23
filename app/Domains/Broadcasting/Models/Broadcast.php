<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Models;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastOrigin;
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
 * @property string|null                     $stop_reason
 * @property BroadcastOrigin                 $origin             Who started it: a person on the console or a flow
 * @property string|null                     $flow_id            The flow whose node started it (automation only)
 * @property string|null                     $flow_session_id    The run that started it (automation only)
 * @property string|null                     $flow_node_id       The node that started it (automation only)
 * @property string|null                     $origin_key         Hash of the node execution that started it, unique
 * @property int                             $chain_depth        The event chain depth its completion event carries
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

    /** @var string */
    protected $table = 'broadcasts';

    /**
     * The database defaults, so a broadcast created without them reads the same before and after a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'origin'      => 'console',
        'chain_depth' => 0,
    ];

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
        'stop_reason',
        'origin',
        'flow_id',
        'flow_session_id',
        'flow_node_id',
        'origin_key',
        'chain_depth',
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
            'origin'           => BroadcastOrigin::class,
            'chain_depth'      => 'integer',
            'total_recipients' => 'integer',
            'sent_count'       => 'integer',
            'failed_count'     => 'integer',
            'skipped_count'    => 'integer',
            'started_at'       => 'datetime',
            'completed_at'     => 'datetime',
        ];
    }
}
