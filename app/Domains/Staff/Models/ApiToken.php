<?php

declare(strict_types=1);

namespace App\Domains\Staff\Models;

use App\Domains\Staff\Enums\Permission;
use Carbon\CarbonInterface;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\Uid\Ulid;

/**
 * A tenant API token (`adr-20261119-tenant-api-tokens`): an integration of the tenant, not of the staff user who
 * issued it. The principal of the `api-token` guard — an {@see Authenticatable}, never a {@see User}, so the
 * administrators' bypass of the Gate never matches it.
 *
 * The plain token is `fpt_<ULID of the row>_<40 base62>`; only the SHA-256 of the secret part is stored. Its
 * abilities are {@see Permission} values. Issued, rotated and revoked only by
 * {@see \App\Domains\Staff\Services\ApiTokenService}.
 *
 * @property string $id
 * @property string $name
 * @property string $token_hash
 * @property string $last_four
 * @property list<string> $abilities
 * @property string|null $created_by
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expiry_notified_at
 * @property Carbon|null $created_at
 */
final class ApiToken extends Model implements Authenticatable
{
    use HasUlidPrimaryKey;

    public const string PREFIX = 'fpt_';

    public const int SECRET_LENGTH = 40;

    /** The whole shape, checked before anything reaches the database: prefix, canonical ULID, 40 base62. */
    public const string PATTERN = '/\Afpt_([0-7][0-9A-HJKMNP-TV-Z]{25})_([0-9A-Za-z]{40})\z/';

    public const ?string UPDATED_AT = null;

    /** @var string */
    protected $table = 'api_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'token_hash',
        'last_four',
        'abilities',
        'created_by',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'last_used_at',
        'expiry_notified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * The ULID the plain token carries for a row id (an RFC 4122 string in the `uuid` column).
     */
    public static function publicIdOf(string $id): string
    {
        return Ulid::fromString($id)->toBase32();
    }

    /**
     * The row id for the ULID part of a plain token whose shape already matched {@see self::PATTERN}.
     */
    public static function rowIdOf(string $publicId): string
    {
        return mb_strtolower(Ulid::fromString($publicId)->toRfc4122());
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The abilities as permissions; a stored value the enum no longer knows is left out.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        $permissions = [];

        foreach ($this->abilities as $ability) {
            $permission = is_string($ability) ? Permission::tryFrom($ability) : null;

            if (null !== $permission) {
                $permissions[] = $permission;
            }
        }

        return $permissions;
    }

    /**
     * Whether the token holds this ability. A permission a token may never hold is never held, whatever the row says.
     */
    public function holds(string $ability): bool
    {
        $permission = Permission::tryFrom($ability);

        if (null === $permission || $permission->isForbiddenToTokens()) {
            return false;
        }

        return in_array($permission, $this->permissions(), true);
    }

    public function isRevoked(): bool
    {
        return null !== $this->revoked_at;
    }

    public function isExpired(?CarbonInterface $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
    }

    public function isUsable(?CarbonInterface $now = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($now);
    }

    /**
     * How the token is named where its actions are recorded.
     */
    public function auditLabel(): string
    {
        return 'API · ' . $this->name;
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        throw new LogicException('An API token has no password.');
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken(mixed $value): void
    {
        // An API token is never remembered.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities'          => 'array',
            'expires_at'         => 'datetime',
            'revoked_at'         => 'datetime',
            'last_used_at'       => 'datetime',
            'expiry_notified_at' => 'datetime',
            'created_at'         => 'datetime',
        ];
    }
}
