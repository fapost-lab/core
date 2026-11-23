<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Flow\Admission\RefusalReason;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;

/**
 * A live trigger's admission state: paused by the run rate breaker or not, and its refused runs.
 *
 * One row per trigger (unique `trigger_id`, deleted with the trigger), made on the trigger's first pause or refusal and
 * written only through {@see \App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface}.
 *
 * @property string                          $id
 * @property string                          $trigger_id
 * @property \Illuminate\Support\Carbon|null $paused_at
 * @property RefusalReason|null              $paused_reason
 * @property \Illuminate\Support\Carbon|null $resumed_at
 * @property int                             $refused_count
 * @property \Illuminate\Support\Carbon|null $last_refused_at
 * @property RefusalReason|null              $last_refusal_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class FlowTriggerState extends BaseModel
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'trigger_id',
        'paused_at',
        'paused_reason',
        'resumed_at',
        'refused_count',
        'last_refused_at',
        'last_refusal_reason',
    ];

    public function isPaused(): bool
    {
        return null !== $this->paused_at;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'paused_at'           => 'datetime',
            'paused_reason'       => RefusalReason::class,
            'resumed_at'          => 'datetime',
            'refused_count'       => 'integer',
            'last_refused_at'     => 'datetime',
            'last_refusal_reason' => RefusalReason::class,
        ];
    }
}
