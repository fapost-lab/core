<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Types;

use App\Domains\Credentials\Contracts\RejectedTokenAwareInterface;
use App\Domains\Credentials\Contracts\ValidatesOwnSettingsInterface;
use App\Domains\Credentials\Models\CredentialOAuthGrant;
use App\Domains\Credentials\OAuth2\OAuth2GrantRefresher;
use App\Domains\Credentials\OAuth2\OAuth2GrantRepository;
use App\Domains\Credentials\OAuth2\OAuth2GrantStatus;
use App\Domains\Credentials\OAuth2\OAuth2TokenEndpoint;
use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Fapost\Foundation\Credentials\DTO\CredentialField;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\DTO\HttpAuthorization;
use Fapost\Foundation\Credentials\Enums\CredentialFieldKind;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * OAuth2 authorization code grant: a person consents once at the provider (from the credential's console page, see
 * {@see \App\Domains\Credentials\OAuth2\OAuth2Consent}), and the grant that consent gave is stored apart from the
 * credential ({@see CredentialOAuthGrant}). A call sends its access token as `Authorization: Bearer <token>`; one that
 * runs out within {@see self::EXPIRY_MARGIN_SECONDS} seconds is refreshed first ({@see OAuth2GrantRefresher}).
 *
 * No grant, or one the provider refused, is `credential_unavailable`. The client is the tenant's own application at
 * the provider: its token URL must be on an allowed host, its authorization URL only https (the browser goes there,
 * not the server, and no secret is sent to it).
 */
final readonly class OAuth2AuthorizationCodeType implements HttpCredentialTypeInterface, RejectedTokenAwareInterface, ValidatesOwnSettingsInterface
{
    public const string ID = 'oauth2_authorization_code';

    public const int EXPIRY_MARGIN_SECONDS = 60;

    /**
     * The settings that name the client and what it asks for: a change to any of them (or to the client secret) drops
     * the grant, since it was given to the client as it was.
     *
     * @var list<string>
     */
    public const array GRANT_BOUND_SETTINGS = ['authorize_url', 'token_url', 'client_id', 'scope', 'authorize_params'];

    /**
     * Authorization request parameters the connection sets itself.
     *
     * @var list<string>
     */
    public const array RESERVED_PARAMS = ['response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope'];

    public function __construct(
        private OAuth2GrantRepository $grants,
        private OAuth2GrantRefresher $refresher,
        private OAuth2TokenEndpoint $endpoint,
    ) {
    }

    /**
     * The extra authorization parameters as the admin saved them; empty when there are none or they do not parse.
     *
     * @return array<string, string>
     */
    public static function extraParams(?string $value): array
    {
        if (null === $value || '' === mb_trim($value)) {
            return [];
        }

        parse_str(mb_ltrim(mb_trim($value), '?'), $params);

        $extra = [];

        foreach ($params as $name => $param) {
            if (is_string($name) && '' !== $name && is_string($param)) {
                $extra[$name] = $param;
            }
        }

        return $extra;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'OAuth 2.0 authorization code (user consent)';
    }

    public function fields(): array
    {
        return [
            // Text, not Url: a URL field must be on an allowed host, and the browser, not the server, goes here.
            new CredentialField(key: 'authorize_url', label: 'Authorization URL'),
            new CredentialField(key: 'token_url', label: 'Token URL', kind: CredentialFieldKind::Url),
            new CredentialField(key: 'client_id', label: 'Client ID'),
            new CredentialField(key: 'scope', label: 'Scope', required: false),
            new CredentialField(
                key: 'client_auth',
                label: 'Send the client credentials',
                kind: CredentialFieldKind::Select,
                choices: [OAuth2TokenEndpoint::CLIENT_AUTH_BASIC, OAuth2TokenEndpoint::CLIENT_AUTH_BODY],
            ),
            new CredentialField(key: 'authorize_params', label: 'Extra authorization parameters', required: false),
            new CredentialField(key: 'client_secret', label: 'Client secret', secret: true),
        ];
    }

    public function authorize(CredentialSecret $secret): HttpAuthorization
    {
        // The refresh goes to the token URL: a credential moved off its token host stops working at once.
        $this->endpoint->checkedUrl($secret);

        $grant = $this->grants->find($secret->id);

        if (null === $grant) {
            throw CredentialUnavailableException::because($secret->id, 'the credential is not connected');
        }

        if (OAuth2GrantStatus::Connected !== $grant->status) {
            throw CredentialUnavailableException::because($secret->id, 'the credential needs to be connected again');
        }

        if (!$grant->accessValidFor(self::EXPIRY_MARGIN_SECONDS)) {
            $grant = $this->refresher->refreshIfDue(
                $secret,
                static fn (CredentialOAuthGrant $current): bool => !$current->accessValidFor(self::EXPIRY_MARGIN_SECONDS),
            );
        }

        $clientId     = (string) $secret->setting('client_id');
        $clientSecret = (string) $secret->value('client_secret');

        try {
            $accessToken  = $grant->access_token;
            $refreshToken = $grant->refresh_token;
        } catch (DecryptException) {
            throw CredentialUnavailableException::because($secret->id, 'the stored grant cannot be decrypted');
        }

        return new HttpAuthorization(
            headers: ['Authorization' => 'Bearer ' . $accessToken],
            secrets: array_values(array_filter(
                [$clientSecret, $accessToken, $refreshToken, base64_encode($clientId . ':' . $clientSecret)],
                static fn (?string $value): bool => null !== $value && '' !== $value,
            )),
        );
    }

    public function tokenRejected(CredentialSecret $secret): void
    {
        $this->grants->markAccessExpired($secret->id);
    }

    public function settingError(string $key, string $value): ?string
    {
        return match ($key) {
            'authorize_url'    => self::isHttpsUrl($value) ? null : __('credential_validation.url_invalid'),
            'authorize_params' => self::paramsError($value),
            default            => null,
        };
    }

    private static function isHttpsUrl(string $value): bool
    {
        $parts = false !== filter_var($value, FILTER_VALIDATE_URL) ? parse_url($value) : false;

        // No user info: a secret in the URL would be stored and shown as a setting. No fragment: the query is ours to add.
        return is_array($parts)
            && 'https' === mb_strtolower((string) ($parts['scheme'] ?? ''))
            && is_string($parts['host'] ?? null)
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['fragment']);
    }

    private static function paramsError(string $value): ?string
    {
        parse_str(mb_ltrim(mb_trim($value), '?'), $params);

        if ([] === $params) {
            return __('credential_validation.authorize_params_invalid');
        }

        foreach ($params as $name => $param) {
            if (!is_string($name) || '' === $name || !is_string($param)) {
                return __('credential_validation.authorize_params_invalid');
            }
        }

        $reserved = array_values(array_intersect(array_map('mb_strtolower', array_keys($params)), self::RESERVED_PARAMS));

        return [] === $reserved ? null : __('credential_validation.authorize_params_reserved', ['keys' => implode(', ', $reserved)]);
    }
}
