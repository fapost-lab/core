<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $language
 * @property string $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation whereLanguage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation whereTenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TenantTranslation whereValue($value)
 * @mixin \Eloquent
 */
final class TenantTranslation extends BaseModel
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'key',
        'language',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'string',
            'key'       => 'string',
            'language'  => 'string',
            'value'     => 'string',
        ];
    }
}
