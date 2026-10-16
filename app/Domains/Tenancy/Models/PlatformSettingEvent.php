<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One change of a platform-wide setting, kept for good. A null value means "not stored": the default applied.
 *
 * Landlord table owned by Tenancy; only {@see \App\Domains\Tenancy\Services\PlatformSettings} writes it.
 *
 * @property string $id
 * @property string $key
 * @property int|bool|string|null $old_value
 * @property int|bool|string|null $new_value
 * @property string $changed_by
 * @property \Illuminate\Support\Carbon $changed_at
 */
final class PlatformSettingEvent extends Model
{
    use HasUlidPrimaryKey;

    /** @var bool */
    public $timestamps = false;

    /** @var string */
    protected $connection = 'landlord';

    /** @var string */
    protected $table = 'platform_setting_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'old_value',
        'new_value',
        'changed_by',
        'changed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value'  => 'json',
            'new_value'  => 'json',
            'changed_at' => 'datetime',
        ];
    }
}
