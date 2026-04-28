<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Flow\Models\Builders\FlowDefinitionBuilder;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $flow_id
 * @property int    $version
 * @property string $name
 * @property array<int, array<string, mixed>> $nodes
 * @property array<int, array<string, mixed>> $edges
 * @property bool   $is_active
 * @property \Illuminate\Support\Carbon|null $published_at
 * @method static FlowDefinitionBuilder<static>|FlowDefinition active()
 * @method static FlowDefinitionBuilder<static>|FlowDefinition newModelQuery()
 * @method static FlowDefinitionBuilder<static>|FlowDefinition newQuery()
 * @method static FlowDefinitionBuilder<static>|FlowDefinition query()
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereCreatedAt($value)
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereEdges($value)
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereFlowId($value)
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereId($value)
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereIsActive($value)
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereName($value)
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereNodes($value)
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereTenantId($value)
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereUpdatedAt($value)
 * @method static FlowDefinitionBuilder<static>|FlowDefinition whereVersion($value)
 * @mixin \Eloquent
 */
final class FlowDefinition extends BaseModel
{
    use HasUlidPrimaryKey;

    /** @var class-string<FlowDefinitionBuilder> */
    protected string $customBuilder = FlowDefinitionBuilder::class;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'flow_id',
        'version',
        'name',
        'nodes',
        'edges',
        'is_active',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nodes'        => 'array',
            'edges'        => 'array',
            'is_active'    => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
