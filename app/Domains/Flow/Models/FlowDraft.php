<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use Database\Factories\FlowDraftFactory;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @method static FlowDraftFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft query()
 * @mixin \Eloquent
 */
final class FlowDraft extends BaseModel
{
    /** @use HasFactory<FlowDraftFactory> */
    use HasFactory;
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'flow_id',
        'draft_version',
        'name',
        'nodes',
    ];

    protected static function newFactory(): FlowDraftFactory
    {
        return FlowDraftFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nodes'         => 'array',
            'draft_version' => 'integer',
        ];
    }
}
