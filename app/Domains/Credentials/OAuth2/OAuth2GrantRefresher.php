<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

use App\Domains\Credentials\Models\CredentialOAuthGrant;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Illuminate\Contracts\Encryption\DecryptException;
use Psr\Log\LoggerInterface;

/**
 * Refreshes the grant of an `oauth2_authorization_code` credential, for a call whose access token has run out and for
 * the keep-alive command alike.
 *
 * A call passes what is left of its timeout: the wait for the lock and the refresh request together stay inside it.
 * The keep-alive command passes no limit.
 *
 * Under the credential's {@see OAuth2GrantLock}: the grant is read again (another worker may have refreshed it while
 * this one waited), and only when it is still due is the refresh token sent. What the provider answers is stored at
 * once — a rotated refresh token before the new access token is used. A provider that refuses the grant
 * (`invalid_grant`, `invalid_client`, `unauthorized_client`) marks it "reconnect needed" with a warning, and no
 * request is made for it again until someone reconnects. Any other failure changes nothing.
 *
 * A refusal is not an audit entry: it is a status change made by a job or a call, which the audit trail does not
 * record (`adr-20261111-audit-log-explicit-recording`). The status, its failure code (shown in the console) and the
 * warning are its record.
 */
final readonly class OAuth2GrantRefresher
{
    /** The failure code of an access token that ended with no refresh token to renew it. */
    public const string ACCESS_EXPIRED = 'access_expired';

    public function __construct(
        private OAuth2GrantRepository $grants,
        private OAuth2TokenEndpoint $endpoint,
        private OAuth2GrantLock $lock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  callable(CredentialOAuthGrant): bool  $isDue  asked of the grant as read under the lock
     * @param  float|null  $seconds  the most the lock wait and the refresh request may take together; null for no limit
     *
     * @return CredentialOAuthGrant the connected grant, refreshed or not
     *
     * @throws CredentialUnavailableException when there is no usable grant, the lock is busy, the stored grant cannot be
     *                                        decrypted or the refresh failed
     */
    public function refreshIfDue(CredentialSecret $secret, callable $isDue, ?float $seconds = null): CredentialOAuthGrant
    {
        $startedAt = hrtime(true);
        $left      = static fn (): ?float => null === $seconds ? null : $seconds - (hrtime(true) - $startedAt) / 1e9;

        try {
            return $this->lock->run(
                $secret->tenantId,
                $secret->id,
                fn (): CredentialOAuthGrant => $this->refreshLocked($secret, $isDue, $left()),
                $seconds,
            );
        } catch (OAuth2GrantLockBusyException) {
            throw CredentialUnavailableException::because($secret->id, 'the grant is being refreshed elsewhere');
        } catch (DecryptException) {
            // A token of the grant is read (decrypted) only here, under the lock: by `$isDue` or for the refresh.
            throw CredentialUnavailableException::because($secret->id, 'the stored grant cannot be decrypted');
        }
    }

    /**
     * @param  callable(CredentialOAuthGrant): bool  $isDue
     * @param  float|null  $seconds  what is left for the refresh request once the lock was had; null for no limit
     */
    private function refreshLocked(CredentialSecret $secret, callable $isDue, ?float $seconds): CredentialOAuthGrant
    {
        $grant = $this->grants->find($secret->id);

        if (null === $grant) {
            throw CredentialUnavailableException::because($secret->id, 'the credential is not connected');
        }

        if (OAuth2GrantStatus::Connected !== $grant->status) {
            throw CredentialUnavailableException::because($secret->id, 'the credential needs to be connected again');
        }

        if (!$isDue($grant)) {
            return $grant;
        }

        $refreshToken = $grant->refresh_token;

        if (null === $refreshToken || '' === $refreshToken) {
            $this->reconnectNeeded($secret, $grant, self::ACCESS_EXPIRED);

            throw CredentialUnavailableException::because($secret->id, 'the access token expired and there is no refresh token');
        }

        try {
            $tokens = $this->endpoint->request($secret, ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $seconds);
        } catch (OAuth2TokenRequestFailedException $failure) {
            if ($failure->isRejection()) {
                $this->reconnectNeeded($secret, $grant, (string) $failure->errorCode);
            }

            throw CredentialUnavailableException::because($secret->id, $failure->getMessage());
        }

        return $this->grants->refreshed($grant, $tokens);
    }

    private function reconnectNeeded(CredentialSecret $secret, CredentialOAuthGrant $grant, string $code): void
    {
        $this->grants->markReconnectNeeded($grant, $code);

        $this->logger->warning('OAuth2 grant needs a new consent: ' . $code, [
            'tenant_id'     => $secret->tenantId,
            'credential_id' => $secret->id,
        ]);
    }
}
