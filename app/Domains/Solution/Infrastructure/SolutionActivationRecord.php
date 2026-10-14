<?php

declare(strict_types=1);

namespace App\Domains\Solution\Infrastructure;

use App\Domains\Solution\Enums\SolutionActivationStatus;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Row of the tenant-schema table `solution_activations`: one per Solution a tenant has ever activated.
 *
 * @property string $id
 * @property string $solution_id
 * @property SolutionActivationStatus $status
 * @property \Illuminate\Support\Carbon $activated_at
 * @property \Illuminate\Support\Carbon|null $deactivated_at
 */
final class SolutionActivationRecord extends Model
{
    use HasUlidPrimaryKey;

    /** @var string */
    protected $table = 'solution_activations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'solution_id',
        'status',
        'activated_at',
        'deactivated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status'         => SolutionActivationStatus::class,
            'activated_at'   => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }
}
