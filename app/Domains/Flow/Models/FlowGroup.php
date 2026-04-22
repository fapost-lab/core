<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Assistant\Models\Assistant;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin \Eloquent
 */
final class FlowGroup extends BaseModel
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'assistant_id',
        'name',
    ];

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(FlowDraft::class, 'flow_group_id');
    }
}
