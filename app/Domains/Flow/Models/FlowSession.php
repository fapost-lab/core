<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\OptimisticLockConflictException;
use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use App\Domains\Shared\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $assistant_id
 * @property string $contact_id
 * @property string $flow_definition_id
 * @property int $flow_version
 * @property string|null $current_node_id
 * @property array<string, mixed> $state
 * @property FlowSessionStatus $status
 * @property int $version
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|FlowSession query()
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
     * @param  array<string, mixed>  $attributes
     */
    public function saveWithOptimisticLock(array $attributes): void
    {
        $updatedAt = now();

        $updated = $this->newQuery()
            ->whereKey($this->getKey())
            ->where('version', $this->version)
            ->update(array_merge(
                $attributes,
                [
                    'version'    => $this->version + 1,
                    'updated_at' => $updatedAt,
                ],
            ));

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
