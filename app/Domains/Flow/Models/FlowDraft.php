<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Assistant\Models\Assistant;
use Database\Factories\FlowDraftFactory;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'assistant_id',
        'flow_group_id',
        'draft_version',
        'name',
        'description',
        'is_public',
        'is_active',
        'nodes',
    ];

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FlowGroup::class, 'flow_group_id');
    }

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
            'is_public'     => 'boolean',
            'is_active'     => 'boolean',
        ];
    }
}
