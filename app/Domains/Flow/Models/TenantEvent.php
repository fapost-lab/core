<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $event_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantEvent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantEvent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantEvent query()
 * @mixin \Eloquent
 */
final class TenantEvent extends BaseModel
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'event_name',
    ];
}
