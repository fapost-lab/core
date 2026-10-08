<?php

declare(strict_types=1);

namespace App\Domains\Staff\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a platform operator into the tenant as its platform support user.
 *
 * Tenant-schema table the tenant's administrators can read: the record of who was in their panel.
 *
 * @property string $id
 * @property string $operator_ref
 * @property string $operator_name
 * @property string $operator_email
 * @property string|null $ip
 * @property \Illuminate\Support\Carbon $entered_at
 * @property \Illuminate\Support\Carbon|null $left_at
 */
final class SupportAccessEntry extends Model
{
    use HasUlidPrimaryKey;

    /** @var bool */
    public $timestamps = false;

    /** @var string */
    protected $table = 'support_access_entries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'operator_ref',
        'operator_name',
        'operator_email',
        'ip',
        'entered_at',
        'left_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'left_at'    => 'datetime',
        ];
    }
}
