<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-only Eloquent surface over the partitioned `flow_logs` table.
 *
 * Writes still go through {@see \App\Domains\Flow\Logging\FlowLogWriter} which
 * inserts via the partitioned root; this model is bound only by Filament for
 * the operational log viewer.
 *
 * @property string                                      $id
 * @property string                                      $session_id
 * @property string                                      $node_id
 * @property string                                      $node_type
 * @property int                                         $node_version
 * @property string                                      $status
 * @property string|null                                 $source_handle
 * @property array<string, mixed>|null                   $state_changes
 * @property array<string, mixed>|null                   $resolved
 * @property array<string, mixed>|null                   $error
 * @property \Illuminate\Support\Carbon                  $created_at
 * @property-read FlowSession                            $session
 */
final class FlowLog extends Model
{
    use HasUlidPrimaryKey;

    /** @var bool */
    public $timestamps = false;

    /** @var string */
    protected $table = 'flow_logs';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return BelongsTo<FlowSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(FlowSession::class, 'session_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state_changes' => 'array',
            'resolved'      => 'array',
            'error'         => 'array',
            'node_version'  => 'integer',
            'created_at'    => 'datetime',
        ];
    }
}
