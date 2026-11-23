<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Models;

use App\Domains\Credentials\OAuth2\OAuth2GrantStatus;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * The grant of an `oauth2_authorization_code` credential: its access and refresh tokens (encrypted at rest and hidden
 * from `toArray()` and JSON), their lifetimes, who connected it and when, and whether it still works.
 *
 * Read and written only through {@see \App\Domains\Credentials\OAuth2\OAuth2GrantRepository}. It has no relation that
 * touches the credential: saving a grant must not change the credential's `updated_at`, which is its version.
 *
 * @property string             $credential_id
 * @property string             $tenant_id
 * @property OAuth2GrantStatus  $status
 * @property string             $access_token
 * @property string|null        $refresh_token
 * @property Carbon|null        $access_expires_at
 * @property Carbon|null        $refresh_expires_at
 * @property string|null        $granted_scope
 * @property string|null        $connected_by
 * @property Carbon             $connected_at
 * @property Carbon             $refreshed_at
 * @property string|null        $failure_code
 * @property Carbon|null        $created_at
 * @property Carbon|null        $updated_at
 */
final class CredentialOAuthGrant extends BaseModel
{
    use HasUlidPrimaryKey;

    /** @var string */
    protected $table = 'credential_oauth_grants';

    /** @var string */
    protected $primaryKey = 'credential_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'credential_id',
        'tenant_id',
        'status',
        'access_token',
        'refresh_token',
        'access_expires_at',
        'refresh_expires_at',
        'granted_scope',
        'connected_by',
        'connected_at',
        'refreshed_at',
        'failure_code',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * The key is the credential's own id, always given; nothing is generated for a grant.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['credential_id'];
    }

    /**
     * Whether the access token is still good for the margin given, in seconds. A token without an expiry never ends.
     */
    public function accessValidFor(int $seconds): bool
    {
        return null === $this->access_expires_at || $this->access_expires_at->isAfter(Carbon::now()->addSeconds($seconds));
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status'             => OAuth2GrantStatus::class,
            'access_token'       => 'encrypted',
            'refresh_token'      => 'encrypted',
            'access_expires_at'  => 'datetime',
            'refresh_expires_at' => 'datetime',
            'connected_at'       => 'datetime',
            'refreshed_at'       => 'datetime',
        ];
    }
}
