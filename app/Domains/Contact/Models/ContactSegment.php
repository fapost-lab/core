<?php

declare(strict_types=1);

namespace App\Domains\Contact\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * Saved audience filter over tenant contacts. `rules` is a small declarative
 * document ({match, conditions[]}) resolved by
 * {@see \App\Domains\Contact\Services\ContactSegmentResolver}; `cached_count` is
 * a denormalized size snapshot for listings (recomputed on demand, not live).
 *
 * @property string                     $id
 * @property string                     $tenant_id
 * @property string                     $name
 * @property array<string, mixed>       $rules
 * @property int|null                   $cached_count
 * @property Carbon|null                $cached_count_at
 */
final class ContactSegment extends BaseModel
{
    use HasUlidPrimaryKey;

    /** @var string */
    protected $table = 'contact_segments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'rules',
        'cached_count',
        'cached_count_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rules'           => 'array',
            'cached_count'    => 'integer',
            'cached_count_at' => 'datetime',
        ];
    }
}
