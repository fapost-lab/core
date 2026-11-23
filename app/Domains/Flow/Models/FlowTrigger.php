<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string|null                     $assistant_id
 * @property string                          $flow_id
 * @property FlowTriggerType|string          $type  a built-in type, or the raw string of a registered (or no longer registered) one
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

    /**
     * The type as stored: a built-in type's value, or a registered type such as "feedback.submitted".
     */
    public function typeName(): string
    {
        return $this->type instanceof FlowTriggerType ? $this->type->value : $this->type;
    }

    /**
     * The built-in type, or null for a type an extension registered (or registered once: a row outlives the Solution
     * that brought its type, and reading it must never fail).
     */
    public function builtInType(): ?FlowTriggerType
    {
        return $this->type instanceof FlowTriggerType ? $this->type : null;
    }

    protected static function booted(): void
    {
        self::saving(static function (FlowTrigger $trigger): void {
            // Eloquent models are instantiated by ORM directly, so validator is resolved lazily here.
            app(FlowTriggerConfigValidatorInterface::class)
                ->validate($trigger->typeName(), $trigger->config);
        });
    }

    /**
     * A built-in type reads as {@see FlowTriggerType}, any other as its string. An enum cast would throw on a row whose
     * type an extension registered; writes take either form.
     *
     * @return Attribute<FlowTriggerType|string, FlowTriggerType|string>
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): FlowTriggerType|string|null => null === $value ? null : (FlowTriggerType::tryFrom($value) ?? $value),
            set: static fn (FlowTriggerType|string $value): string => $value instanceof FlowTriggerType ? $value->value : $value,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active'   => 'boolean',
            'priority'    => 'integer',
            'config'      => 'array',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }
}
