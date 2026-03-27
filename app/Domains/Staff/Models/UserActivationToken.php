<?php

declare(strict_types=1);

namespace App\Domains\Staff\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single active activation token per user (enforced by unique user_id).
 *
 * @property-read User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserActivationToken query()
 * @mixin \Eloquent
 */
final class UserActivationToken extends Model
{
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
