<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;

/**
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string|null                     $assistant_id
 * @property string                          $flow_id
 * @property FlowTriggerType                 $type
 * @property bool                            $is_active
 * @property int                             $priority
 * @property array<string, mixed>            $config
 * @property \Illuminate\Support\Carbon|null $last_run_at
 * @property \Illuminate\Support\Carbon|null $next_run_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger query()
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereAssistantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereFlowId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereLastRunAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereNextRunAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowTrigger whereUpdatedAt($value)
 * @mixin \Eloquent
 */
final class FlowTrigger extends BaseModel
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'assistant_id',
        'flow_id',
        'type',
        'is_active',
        'priority',
        'config',
        'last_run_at',
        'next_run_at',
    ];

    protected static function booted(): void
    {
        self::saving(static function (FlowTrigger $trigger): void {
            // Eloquent models are instantiated by ORM directly, so validator is resolved lazily here.
            app(FlowTriggerConfigValidatorInterface::class)
                ->validate($trigger->type->value, $trigger->config);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'        => FlowTriggerType::class,
            'is_active'   => 'boolean',
            'priority'    => 'integer',
            'config'      => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }
}
