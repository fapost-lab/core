<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use Fapost\Foundation\Flow\History\HistoryEventType;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single row in flow_session_history — opt-in audit trail of state mutations
 * and lifecycle events. Engine and ContactWriter write here when the running
 * flow_definition has logging_enabled = true; otherwise NoOpHistoryWriter
 * absorbs the call.
 *
 * Distinct from flow_logs (operational, 30-day retention, partitioned). This
 * is business audit; retention is currently manual/forever (V1.x will add
 * per-tenant policy).
 *
 * @property string                                  $id
 * @property string                                  $tenant_id
 * @property string                                  $session_id
 * @property string                                  $node_id
 * @property HistoryEventType                        $event_type
 * @property string|null                             $path
 * @property mixed                                   $old_value
 * @property mixed                                   $new_value
 * @property array<string, mixed>|null               $metadata
 * @property \Illuminate\Support\Carbon              $created_at
 * @property-read FlowSession                        $session
 */
final class FlowSessionHistoryEntry extends BaseModel
{
    use HasUlidPrimaryKey;

    public $timestamps = false;

    protected $table = 'flow_session_history';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'session_id',
        'node_id',
        'event_type',
        'path',
        'old_value',
        'new_value',
        'metadata',
        'created_at',
    ];

    /**
     * @return BelongsTo<FlowSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(FlowSession::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => HistoryEventType::class,
            'old_value'  => 'array',
            'new_value'  => 'array',
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }
}
