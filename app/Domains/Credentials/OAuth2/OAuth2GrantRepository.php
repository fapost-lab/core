<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\Models\CredentialOAuthGrant;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The grants of the current tenant's `oauth2_authorization_code` credentials. Every read and write is bounded by the
 * tenant {@see TenantContextInterface::get()} names, so it is bound scoped.
 *
 * It writes rows only; the lock around a refresh and the audit entries are its callers'.
 */
final readonly class OAuth2GrantRepository
{
    /** A grant not refreshed for this long is refreshed by the keep-alive command, so the provider does not expire it. */
    public const int KEEP_ALIVE_IDLE_DAYS = 7;

    /** A refresh token that ends within this many days is used before it does. */
    public const int KEEP_ALIVE_REFRESH_EXPIRY_DAYS = 3;

    public function __construct(private TenantContextInterface $tenants)
    {
    }

    /**
     * Whether the keep-alive command should refresh the grant; the same rule as {@see self::dueForKeepAlive()}, asked
     * again under the lock.
     */
    public static function keepAliveDue(CredentialOAuthGrant $grant): bool
    {
        $now = Carbon::now();

        return OAuth2GrantStatus::Connected === $grant->status
            && null !== $grant->refresh_token
            && ($grant->refreshed_at->lessThanOrEqualTo($now->copy()->subDays(self::KEEP_ALIVE_IDLE_DAYS))
                || (null !== $grant->refresh_expires_at && $grant->refresh_expires_at->lessThanOrEqualTo($now->copy()->addDays(self::KEEP_ALIVE_REFRESH_EXPIRY_DAYS))));
    }

    public function find(string $credentialId): ?CredentialOAuthGrant
    {
        if (!Str::isUuid($credentialId)) {
            return null;
        }

        return CredentialOAuthGrant::query()
            ->where('tenant_id', $this->tenantId())
            ->whereKey($credentialId)
            ->first();
    }

    /**
     * The credential a grant belongs to, in the current tenant; null when there is none.
     */
    public function credential(string $credentialId): ?Credential
    {
        if (!Str::isUuid($credentialId)) {
            return null;
        }

        return Credential::query()->where('tenant_id', $this->tenantId())->whereKey($credentialId)->first();
    }

    /**
     * The credential a grant belongs to, read again and locked for update until the caller's transaction ends: a
     * concurrent edit or delete of the credential waits for it, and one committed before is seen. Null when there is
     * none in the current tenant.
     */
    public function lockedCredential(string $credentialId): ?Credential
    {
        if (!Str::isUuid($credentialId)) {
            return null;
        }

        return Credential::query()->where('tenant_id', $this->tenantId())->whereKey($credentialId)->lockForUpdate()->first();
    }

    /**
     * Stores the grant a consent gave, over any grant the credential had.
     */
    public function connect(string $credentialId, OAuth2TokenResponse $tokens, ?string $connectedBy): CredentialOAuthGrant
    {
        $now   = Carbon::now();
        $grant = $this->find($credentialId) ?? new CredentialOAuthGrant(['credential_id' => $credentialId, 'tenant_id' => $this->tenantId()]);

        $grant->fill([
            'status'             => OAuth2GrantStatus::Connected,
            'access_token'       => $tokens->accessToken(),
            'refresh_token'      => $tokens->refreshToken(),
            'access_expires_at'  => self::expiry($now, $tokens->expiresIn),
            'refresh_expires_at' => self::expiry($now, $tokens->refreshExpiresIn),
            'granted_scope'      => $tokens->scope,
            'connected_by'       => $connectedBy,
            'connected_at'       => $now,
            'refreshed_at'       => $now,
            'failure_code'       => null,
        ])->save();

        return $grant;
    }

    /**
     * Stores what a refresh gave. A provider that rotates the refresh token sends a new one, which replaces the old;
     * one that does not leaves the old one in place, with its expiry.
     */
    public function refreshed(CredentialOAuthGrant $grant, OAuth2TokenResponse $tokens): CredentialOAuthGrant
    {
        $now = Carbon::now();

        $grant->fill([
            'status'            => OAuth2GrantStatus::Connected,
            'access_token'      => $tokens->accessToken(),
            'access_expires_at' => self::expiry($now, $tokens->expiresIn),
            'refreshed_at'      => $now,
            'failure_code'      => null,
        ]);

        if (null !== $tokens->refreshToken()) {
            $grant->fill(['refresh_token' => $tokens->refreshToken(), 'refresh_expires_at' => self::expiry($now, $tokens->refreshExpiresIn)]);
        }

        $grant->save();

        return $grant;
    }

    /**
     * @param  string  $failureCode  the provider's `error` code, or a fixed word of ours; never free text
     */
    public function markReconnectNeeded(CredentialOAuthGrant $grant, string $failureCode): void
    {
        $grant->fill(['status' => OAuth2GrantStatus::ReconnectNeeded, 'failure_code' => mb_substr($failureCode, 0, 64)])->save();
    }

    /**
     * The target refused the access token: the next call refreshes it (or, without a refresh token, asks for a new
     * consent). Nothing is requested here.
     */
    public function markAccessExpired(string $credentialId): void
    {
        if (!Str::isUuid($credentialId)) {
            return;
        }

        CredentialOAuthGrant::query()
            ->where('tenant_id', $this->tenantId())
            ->whereKey($credentialId)
            ->update(['access_expires_at' => Carbon::now()->subSecond(), 'updated_at' => Carbon::now()]);
    }

    /**
     * @return bool whether there was a grant to delete
     */
    public function delete(string $credentialId): bool
    {
        if (!Str::isUuid($credentialId)) {
            return false;
        }

        return CredentialOAuthGrant::query()->where('tenant_id', $this->tenantId())->whereKey($credentialId)->delete() > 0;
    }

    /**
     * The connected grants with a refresh token that the keep-alive command refreshes: idle for
     * {@see self::KEEP_ALIVE_IDLE_DAYS} days, or with a refresh token that ends within
     * {@see self::KEEP_ALIVE_REFRESH_EXPIRY_DAYS} days.
     *
     * @return Collection<int, CredentialOAuthGrant>
     */
    public function dueForKeepAlive(): Collection
    {
        $now = Carbon::now();

        return CredentialOAuthGrant::query()
            ->where('tenant_id', $this->tenantId())
            ->where('status', OAuth2GrantStatus::Connected->value)
            ->whereNotNull('refresh_token')
            ->where(static function ($query) use ($now): void {
                $query->where('refreshed_at', '<=', $now->copy()->subDays(self::KEEP_ALIVE_IDLE_DAYS))
                    ->orWhere('refresh_expires_at', '<=', $now->copy()->addDays(self::KEEP_ALIVE_REFRESH_EXPIRY_DAYS));
            })
            ->orderBy('refreshed_at')
            ->get();
    }

    private static function expiry(Carbon $now, ?int $seconds): ?Carbon
    {
        return null === $seconds ? null : $now->copy()->addSeconds($seconds);
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
