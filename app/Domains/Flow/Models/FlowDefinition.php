<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Flow\Models\Builders\FlowDefinitionBuilder;
use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use App\Domains\Shared\Models\BaseModel;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $flow_id
 * @property int $version
 * @property string $name
 * @property array<int, array<string, mixed>> $nodes
 * @property array<int, array<string, mixed>> $edges
 * @property bool $is_active
 * @method static FlowDefinitionBuilder<static>|FlowDefinition active()
 * @method static FlowDefinitionBuilder<static>|FlowDefinition newModelQuery()
 * @method static FlowDefinitionBuilder<static>|FlowDefinition newQuery()
 * @method static FlowDefinitionBuilder<static>|FlowDefinition query()
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nodes'     => 'array',
            'edges'     => 'array',
            'is_active' => 'boolean',
        ];
    }
}
