<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Flow\State\Variables\VariableType;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;

/**
 * Persisted declaration of a user variable's semantic type within a tenant schema.
 *
 * One row per unique (storage, group, name) triple per tenant. Upserted atomically
 * by PublishFlowService after each successful flow publish. Cascades to null when
 * the declaring flow_definition is deleted.
 *
 * @property string                     $id
 * @property string                     $tenant_id
 * @property string                     $storage              'session' | 'contact'
 * @property string|null                $group
 * @property string                     $name
 * @property VariableType               $type
 * @property array<string, mixed>       $properties           Type-specific metadata; for Array: {max_size, item_type}
 * @property string|null                $declared_in_flow_id
 * @property string|null                $declared_by_node_id
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class VariableSchemaEntry extends BaseModel
{
    use HasUlidPrimaryKey;

    /** @var bool */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'storage',
        'group',
        'name',
        'type',
        'properties',
        'declared_in_flow_id',
        'declared_by_node_id',
        'updated_at',
    ];

    public function getTable(): string
    {
        return 'tenant_variable_schema';
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type'       => VariableType::class,
            'properties' => 'array',
            'updated_at' => 'datetime',
        ];
    }
}
