<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Assistant\Models\Assistant;
use Database\Factories\FlowDraftFactory;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @method static FlowDraftFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft query()
 * @property string                  $id
 * @property string                  $tenant_id
 * @property string                  $flow_id
 * @property string                  $assistant_id
 * @property string|null             $flow_group_id
 * @property int                     $draft_version
 * @property string                  $name
 * @property string|null             $description
 * @property bool                    $is_public
 * @property bool                    $is_active
 * @property bool                    $logging_enabled
 * @property array<array-key, mixed> $nodes
 * @property array<array-key, mixed> $edges
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Assistant|null     $assistant
 * @property-read FlowGroup|null     $group
 * @property-read FlowTrigger|null   $trigger
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereAssistantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereDraftVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereFlowId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereFlowGroupId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereNodes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowDraft whereUpdatedAt($value)
 * @mixin \Eloquent
 */
final class FlowDraft extends BaseModel
{
    /** @use HasFactory<FlowDraftFactory> */
    use HasFactory;
    use HasUlidPrimaryKey;

    /**
     * Limit key under which a tenant's flow count is capped. A flow is one draft row; its
     * published versions do not count.
     */
    public const string LIMIT_KEY = 'flows';

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
        'logging_enabled',
        'nodes',
        'edges',
    ];

    /**
     * How many flows the tenant has, across all its assistants.
     *
     * The assistant panel scopes this model to the current assistant through Filament's
     * tenancy global scope ({@see Assistant::PANEL_TENANCY_SCOPE}); the limit counts the whole tenant.
     */
    public static function countForLimit(): int
    {
        return self::query()->withoutGlobalScope(Assistant::PANEL_TENANCY_SCOPE)->count();
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FlowGroup::class, 'flow_group_id');
    }

    public function trigger(): HasOne
    {
        return $this->hasOne(FlowTrigger::class, 'flow_id', 'flow_id');
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
            'nodes'           => 'array',
            'edges'           => 'array',
            'draft_version'   => 'integer',
            'is_public'       => 'boolean',
            'is_active'       => 'boolean',
            'logging_enabled' => 'boolean',
        ];
    }
}
