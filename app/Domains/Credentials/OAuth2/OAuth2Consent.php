<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Credentials\EloquentCredentialStore;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\Types\OAuth2AuthorizationCodeType;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\Exceptions\CredentialNotFoundException;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The console's side of an authorization code consent (RFC 6749 §4.1 with PKCE, RFC 7636).
 *
 * {@see self::start()} leaves a pending attempt in the person's session — the state, the PKCE verifier, the redirect
 * URI, the credential and its version, who started it, and when it ends ({@see self::TTL_SECONDS}) — and answers the
 * provider's authorization URL. {@see self::complete()} takes the attempt out of the session by its state (once: a
 * replayed callback finds nothing), checks that it is the same person's, not expired, and that the credential is still
 * there unchanged, exchanges the code with the same redirect URI and verifier, and stores the grant under the
 * credential's lock with its audit entry, after checking once more, on the credential's row locked for update, that it
 * is still there unchanged. No code, token, verifier or state reaches a log, the audit or an exception.
 */
final readonly class OAuth2Consent
{
    public const string SESSION_KEY = 'credentials.oauth2_consents';

    public const int TTL_SECONDS = 600;

    public const int MAX_PENDING = 5;

    public function __construct(
        private TenantContextInterface $tenants,
        private CredentialStoreInterface $store,
        private OAuth2GrantRepository $grants,
        private OAuth2TokenEndpoint $endpoint,
        private OAuth2GrantLock $lock,
        private AuditTrailInterface $audit,
    ) {
    }

    /**
     * The PKCE S256 challenge of a verifier: base64url of its SHA-256, without padding.
     */
    public static function challenge(string $verifier): string
    {
        return mb_rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param  string  $redirectUri  the tenant's callback URL, absolute, exactly as registered at the provider
     *
     * @return string the URL to send the browser to
     */
    public function start(Credential $credential, string $userId, Session $session, string $redirectUri): string
    {
        $state    = Str::random(43);
        $verifier = Str::random(64);
        $now      = Carbon::now()->getTimestamp();

        $pending = array_filter(
            self::pending($session),
            static fn (array $attempt): bool => $attempt['expires_at'] > $now,
        );

        $pending[$state] = [
            'tenant_id'     => $this->tenants->get()->getId(),
            'credential_id' => (string) $credential->getKey(),
            'version'       => EloquentCredentialStore::versionOf($credential),
            'user_id'       => $userId,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $verifier,
            'expires_at'    => $now + self::TTL_SECONDS,
        ];

        $session->put(self::SESSION_KEY, array_slice($pending, -self::MAX_PENDING, preserve_keys: true));

        $settings = $credential->settings;
        $params   = [
            ...OAuth2AuthorizationCodeType::extraParams($settings['authorize_params'] ?? null),
            'response_type'         => 'code',
            'client_id'             => (string) ($settings['client_id'] ?? ''),
            'redirect_uri'          => $redirectUri,
            'state'                 => $state,
            'code_challenge'        => self::challenge($verifier),
            'code_challenge_method' => 'S256',
        ];

        $scope = mb_trim((string) ($settings['scope'] ?? ''));

        if ('' !== $scope) {
            $params['scope'] = $scope;
        }

        $authorizeUrl = (string) ($settings['authorize_url'] ?? '');

        return $authorizeUrl . (str_contains($authorizeUrl, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param  array<string, mixed>  $query  the callback's query: `state`, and `code` or `error`
     *
     * @throws OAuth2ConsentFailedException
     */
    public function complete(array $query, string $userId, Session $session): Credential
    {
        $attempt = $this->takeAttempt($query['state'] ?? null, $session);

        if (null === $attempt || $attempt['user_id'] !== $userId || $attempt['tenant_id'] !== $this->tenants->get()->getId()) {
            throw OAuth2ConsentFailedException::stale();
        }

        $credentialId = $attempt['credential_id'];
        $credential   = $this->grants->credential($credentialId);

        if (null === $credential) {
            throw OAuth2ConsentFailedException::stale();
        }

        if (OAuth2AuthorizationCodeType::ID !== $credential->type || EloquentCredentialStore::versionOf($credential) !== $attempt['version']) {
            throw OAuth2ConsentFailedException::stale($credentialId);
        }

        if (isset($query['error'])) {
            throw OAuth2ConsentFailedException::providerError($credentialId, OAuth2TokenEndpoint::errorCode($query['error']));
        }

        $code = $query['code'] ?? null;

        if (!is_string($code) || '' === $code) {
            throw OAuth2ConsentFailedException::exchangeFailed($credentialId);
        }

        try {
            $tokens = $this->endpoint->request($this->store->secret($credentialId), [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $attempt['redirect_uri'],
                'code_verifier' => $attempt['code_verifier'],
            ]);
        } catch (OAuth2TokenRequestFailedException|CredentialUnavailableException|CredentialNotFoundException) {
            throw OAuth2ConsentFailedException::exchangeFailed($credentialId);
        }

        try {
            return $this->lock->run($attempt['tenant_id'], $credentialId, fn (): Credential => DB::transaction(function () use ($attempt, $credentialId, $tokens, $userId): Credential {
                // The code exchange took time: an edit of the client (which drops the grant) or a delete may have
                // committed meanwhile. Read the credential again under a row lock, so the grant is written only for
                // the client the person consented to, and never for a credential that is gone.
                $current = $this->grants->lockedCredential($credentialId);

                if (null === $current) {
                    throw OAuth2ConsentFailedException::stale();
                }

                if (OAuth2AuthorizationCodeType::ID !== $current->type || EloquentCredentialStore::versionOf($current) !== $attempt['version']) {
                    throw OAuth2ConsentFailedException::stale($credentialId);
                }

                $grant = $this->grants->connect($credentialId, $tokens, $userId);

                $this->record(AuditAction::CredentialConnected, $current, ['granted_scope' => $grant->granted_scope]);

                return $current;
            }));
        } catch (OAuth2GrantLockBusyException) {
            throw OAuth2ConsentFailedException::lockBusy($credentialId);
        }
    }

    /**
     * Deletes the credential's grant. The provider keeps it until it is revoked there.
     *
     * @return bool whether there was a grant
     */
    public function disconnect(Credential $credential): bool
    {
        return DB::transaction(function () use ($credential): bool {
            if (!$this->grants->delete((string) $credential->getKey())) {
                return false;
            }

            $this->record(AuditAction::CredentialDisconnected, $credential);

            return true;
        });
    }

    /**
     * @return array<string, array{tenant_id: string, credential_id: string, version: string, user_id: string, redirect_uri: string, code_verifier: string, expires_at: int}>
     */
    private static function pending(Session $session): array
    {
        $stored  = $session->get(self::SESSION_KEY);
        $pending = [];

        if (!is_array($stored)) {
            return [];
        }

        foreach ($stored as $state => $attempt) {
            if (is_string($state) && is_array($attempt) && self::isAttempt($attempt)) {
                /** @var array{tenant_id: string, credential_id: string, version: string, user_id: string, redirect_uri: string, code_verifier: string, expires_at: int} $attempt */
                $pending[$state] = $attempt;
            }
        }

        return $pending;
    }

    /**
     * @param  array<mixed>  $attempt
     */
    private static function isAttempt(array $attempt): bool
    {
        foreach (['tenant_id', 'credential_id', 'version', 'user_id', 'redirect_uri', 'code_verifier'] as $key) {
            if (!is_string($attempt[$key] ?? null)) {
                return false;
            }
        }

        return is_int($attempt['expires_at'] ?? null);
    }

    /**
     * Removes the attempt from the session whatever comes next, so a state works once.
     *
     * @return array{tenant_id: string, credential_id: string, version: string, user_id: string, redirect_uri: string, code_verifier: string, expires_at: int}|null
     */
    private function takeAttempt(mixed $state, Session $session): ?array
    {
        if (!is_string($state) || '' === $state) {
            return null;
        }

        $pending = self::pending($session);
        $attempt = $pending[$state] ?? null;

        unset($pending[$state]);
        $session->put(self::SESSION_KEY, $pending);

        if (null === $attempt || $attempt['expires_at'] <= Carbon::now()->getTimestamp()) {
            return null;
        }

        return $attempt;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function record(AuditAction $action, Credential $credential, array $properties = []): void
    {
        $this->audit->record(new AuditRecord(
            action: $action,
            subject: $credential,
            subjectLabel: $credential->name,
            properties: ['type' => $credential->type, ...$properties],
        ));
    }
}
