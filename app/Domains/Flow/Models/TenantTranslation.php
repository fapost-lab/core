<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;

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
