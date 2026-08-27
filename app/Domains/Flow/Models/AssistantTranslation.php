<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;

/**
 * @property string $id
 * @property string $assistant_id
 * @property string $key
 * @property string $language
 * @property string $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class AssistantTranslation extends BaseModel
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'assistant_id',
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
            'assistant_id' => 'string',
            'key'          => 'string',
            'language'     => 'string',
            'value'        => 'string',
        ];
    }
}
