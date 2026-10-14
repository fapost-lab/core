<?php

declare(strict_types=1);

namespace App\Domains\Solution\Infrastructure;

use App\Domains\Solution\Enums\ActivationAction;
use App\Domains\Solution\Enums\ActivationActorKind;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Row of `solution_activation_events`, the append-only journal. Written only by the store, in the same
 * transaction as the activation row, and never updated or deleted.
 *
 * @property string $id
 * @property string $solution_id
 * @property ActivationAction $action
 * @property ActivationActorKind $actor_kind
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon $occurred_at
 */
final class SolutionActivationEventRecord extends Model
{
    use HasUlidPrimaryKey;

    /** @var bool */
    public $timestamps = false;

    /** @var string */
    protected $table = 'solution_activation_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'solution_id',
        'action',
        'actor_kind',
        'actor_id',
        'actor_label',
        'reason',
        'occurred_at',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('The activation journal is append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('The activation journal is append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action'      => ActivationAction::class,
            'actor_kind'  => ActivationActorKind::class,
            'occurred_at' => 'datetime',
        ];
    }
}
