<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string              $id
 * @property string              $tenant_id
 * @property string              $assistant_id
 * @property string              $contact_id
 * @property string              $flow_definition_id  FK to {@see FlowDefinition::$id} — immutable
 *           definition snapshot for this session (specific version row), not only logical
 *           {@see FlowDefinition::$flow_id}
 * @property int                 $flow_version
 * @property string|null         $current_node_id
 * @property array<string, mixed> $state
 * @property FlowSessionStatus   $status
 * @property int                 $version
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession query()
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Assistant      $assistant
 * @property-read Contact        $contact
 * @property-read FlowDefinition $flowDefinition
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereAssistantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereContactId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereCurrentNodeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereFlowDefinitionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereFlowVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession whereVersion($value)
 * @mixin \Eloquent
 */
final class FlowSession extends BaseModel
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'assistant_id',
        'contact_id',
        'flow_definition_id',
        'flow_version',
        'current_node_id',
        'state',
        'status',
        'end_status',
        'parent_session_id',
        'parent_resume_node_id',
        'version',
        'expires_at',
    ];

    /**
     * @return BelongsTo<Assistant, $this>
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<FlowDefinition, $this>
     */
    public function flowDefinition(): BelongsTo
    {
        return $this->belongsTo(FlowDefinition::class);
    }

    /**
     * Opt-in audit trail rows persisted by DefaultHistoryWriter while this
     * session was running. Empty unless the underlying flow_definition had
     * logging_enabled = true at execution time.
     *
     * @return HasMany<FlowSessionHistoryEntry, $this>
     */
    public function historyEntries(): HasMany
    {
        return $this->hasMany(FlowSessionHistoryEntry::class, 'session_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function saveWithOptimisticLock(array $attributes): void
    {
        $updatedAt = now();

        $updated = $this->newQuery()
            ->whereKey($this->getKey())
            ->where('version', $this->version)
            ->update(
                array_merge(
                    $attributes,
                    [
                        'version'    => $this->version + 1,
                        'updated_at' => $updatedAt,
                    ],
                )
            );

        if (1 !== $updated) {
            throw new OptimisticLockConflictException(
                "Optimistic lock conflict for flow session '{$this->getKey()}' at version {$this->version}."
            );
        }

        $this->fill($attributes);
        $this->version++;
        $this->updated_at = $updatedAt;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state'      => 'array',
            'status'     => FlowSessionStatus::class,
            'version'    => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
