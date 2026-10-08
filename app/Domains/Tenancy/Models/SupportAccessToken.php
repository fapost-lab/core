<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A single-use, short-lived permission to enter a tenant as its platform support user.
 *
 * Only the sha256 hash of the token is stored. Landlord table owned by Tenancy.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $token_hash
 * @property string $operator_ref
 * @property string $operator_name
 * @property string $operator_email
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class SupportAccessToken extends Model
{
    use HasUlidPrimaryKey;

    /** @var bool */
    public $timestamps = false;

    /** @var string */
    protected $connection = 'landlord';

    /** @var string */
    protected $table = 'support_access_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'token_hash',
        'operator_ref',
        'operator_name',
        'operator_email',
        'expires_at',
        'used_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
