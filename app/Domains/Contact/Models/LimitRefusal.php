<?php

declare(strict_types=1);

namespace App\Domains\Contact\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One day's refusals of one person at one channel under one per-period limit, for the tenant's
 * administrators.
 *
 * Holds no messenger id in clear: the person is a SHA-256 hash of tenant, platform and messenger id,
 * and only the count of refused messages is kept. The hash is pseudonymous, not anonymous: numeric
 * messenger ids can be brute-forced by anyone who knows the tenant id. It keeps plain ids out of the
 * operator's tables and the logs; it does not make the rows non-personal data. Rows older than the retention period are removed by `limit-refusals:prune`.
 *
 * @property string $id
 * @property string $limit_key
 * @property string $subject_hash
 * @property string $channel_id
 * @property \Illuminate\Support\Carbon $refused_on
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $last_refused_at
 */
final class LimitRefusal extends Model
{
    use HasUlidPrimaryKey;

    /** @var string */
    protected $table = 'limit_refusals';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'limit_key',
        'subject_hash',
        'channel_id',
        'refused_on',
        'attempts',
        'last_refused_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refused_on'      => 'date',
            'attempts'        => 'integer',
            'last_refused_at' => 'datetime',
        ];
    }
}
