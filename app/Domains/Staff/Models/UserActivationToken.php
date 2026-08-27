<?php

declare(strict_types=1);

namespace App\Domains\Staff\Models;

use FAPost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single active activation token per user (enforced by unique user_id).
 *
 * @property-read User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken query()
 * @property string $id
 * @property string $user_id
 * @property string $token
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon $created_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken whereToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken whereUserId($value)
 * @mixin \Eloquent
 */
final class UserActivationToken extends Model
{
    use HasUlidPrimaryKey;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
