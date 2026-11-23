<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Types;

use App\Domains\Credentials\Contracts\RejectedTokenAwareInterface;
use App\Domains\Credentials\OAuth2\OAuth2TokenEndpoint;
use App\Domains\Credentials\OAuth2\OAuth2TokenRequestFailedException;
use App\Domains\Credentials\OAuth2TokenCache;
use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Fapost\Foundation\Credentials\DTO\CredentialField;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\DTO\HttpAuthorization;
use Fapost\Foundation\Credentials\Enums\CredentialFieldKind;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;

/**
 * OAuth2 client credentials grant: the access token comes from the token endpoint (once per its lifetime, through
 * {@see OAuth2TokenCache}) and is sent as `Authorization: Bearer <token>`.
 *
 * The token request goes through {@see OAuth2TokenEndpoint}: https, an allowed host, the egress guard, no redirect,
 * and a failure reported by its status alone. A 401 from the target drops the cached token.
 */
final readonly class OAuth2ClientCredentialsType implements HttpCredentialTypeInterface, RejectedTokenAwareInterface
{
    public const string ID = 'oauth2_client_credentials';

    public const string CLIENT_AUTH_BASIC = OAuth2TokenEndpoint::CLIENT_AUTH_BASIC;

    public const string CLIENT_AUTH_BODY = OAuth2TokenEndpoint::CLIENT_AUTH_BODY;

    public function __construct(
        private OAuth2TokenEndpoint $endpoint,
        private OAuth2TokenCache $cache,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'OAuth 2.0 client credentials';
    }

    public function fields(): array
    {
        return [
            new CredentialField(key: 'token_url', label: 'Token URL', kind: CredentialFieldKind::Url),
            new CredentialField(key: 'client_id', label: 'Client ID'),
            new CredentialField(key: 'scope', label: 'Scope', required: false),
            new CredentialField(
                key: 'client_auth',
                label: 'Send the client credentials',
                kind: CredentialFieldKind::Select,
                choices: [self::CLIENT_AUTH_BASIC, self::CLIENT_AUTH_BODY],
            ),
            new CredentialField(key: 'client_secret', label: 'Client secret', secret: true),
        ];
    }

    public function authorize(CredentialSecret $secret): HttpAuthorization
    {
        $clientId     = (string) $secret->setting('client_id');
        $clientSecret = (string) $secret->value('client_secret');
        $token        = null;

        // Checked before the cache is read, so a credential moved off its token host stops working at once.
        $this->endpoint->checkedUrl($secret);

        if ('' !== $clientId && '' !== $clientSecret) {
            $token = $this->cache->get($secret);
        }

        if (null === $token) {
            $token = $this->fetchToken($secret);
        }

        return new HttpAuthorization(
            headers: ['Authorization' => 'Bearer ' . $token],
            secrets: [$clientSecret, $token, base64_encode($clientId . ':' . $clientSecret)],
        );
    }

    public function tokenRejected(CredentialSecret $secret): void
    {
        $this->cache->forget($secret->tenantId, $secret->id);
    }

    private function fetchToken(CredentialSecret $secret): string
    {
        $form  = ['grant_type' => 'client_credentials'];
        $scope = $secret->setting('scope');

        if (null !== $scope && '' !== mb_trim($scope)) {
            $form['scope'] = mb_trim($scope);
        }

        try {
            $response = $this->endpoint->request($secret, $form);
        } catch (OAuth2TokenRequestFailedException $failure) {
            throw CredentialUnavailableException::because($secret->id, $failure->getMessage());
        }

        $this->cache->put($secret, $response->accessToken(), $response->expiresIn);

        return $response->accessToken();
    }
}
