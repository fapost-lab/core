<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A platform-wide setting someone stored; without a row the setting's default applies.
 *
 * Landlord table owned by Tenancy; only {@see \App\Domains\Tenancy\Services\PlatformSettings} reads and writes it.
 *
 * @property string $id
 * @property string $key
 * @property int|bool|string $value
 * @property \Illuminate\Support\Carbon $updated_at
 * @property string $updated_by
 */
final class PlatformSetting extends Model
{
    use HasUlidPrimaryKey;

    /** @var bool */
    public $timestamps = false;

    /** @var string */
    protected $connection = 'landlord';

    /** @var string */
    protected $table = 'platform_settings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'updated_at',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value'      => 'json',
            'updated_at' => 'datetime',
        ];
    }
}
