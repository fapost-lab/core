<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Types;

use App\Domains\Credentials\HostMatcher;
use App\Domains\Credentials\OAuth2TokenCache;
use App\Domains\Flow\Call\Egress\EgressDeniedException;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Fapost\Foundation\Credentials\DTO\CredentialField;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\DTO\HttpAuthorization;
use Fapost\Foundation\Credentials\Enums\CredentialFieldKind;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Psr\Log\LoggerInterface;

/**
 * OAuth2 client credentials grant: the access token comes from the token endpoint (once per its lifetime, through
 * {@see OAuth2TokenCache}) and is sent as `Authorization: Bearer <token>`.
 *
 * The endpoint is a tenant-chosen URL, so it is reached through the egress guard, never follows a redirect and
 * must be on one of the credential's allowed hosts: the client secret is only ever sent where the admin said.
 * A failure is reported by its status alone, never by a response body.
 */
final readonly class OAuth2ClientCredentialsType implements HttpCredentialTypeInterface
{
    public const string ID = 'oauth2_client_credentials';

    public const string CLIENT_AUTH_BASIC = 'basic';

    public const string CLIENT_AUTH_BODY = 'body';

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private GuardedHttpClient $http,
        private OAuth2TokenCache $cache,
        private LoggerInterface $logger,
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
        $tokenUrl     = $secret->setting('token_url');
        $clientId     = $secret->setting('client_id');
        $clientSecret = $secret->value('client_secret');
        $clientAuth   = $secret->setting('client_auth') ?? self::CLIENT_AUTH_BASIC;

        if (null === $tokenUrl || '' === $tokenUrl || null === $clientId || '' === $clientId || null === $clientSecret || '' === $clientSecret) {
            throw CredentialUnavailableException::because($secret->id, 'the token URL, the client id or the client secret is missing');
        }

        if (!in_array($clientAuth, [self::CLIENT_AUTH_BASIC, self::CLIENT_AUTH_BODY], true)) {
            throw CredentialUnavailableException::because($secret->id, 'the client authentication method is not valid');
        }

        $parts = parse_url($tokenUrl);

        if (!is_array($parts) || 'https' !== mb_strtolower((string) ($parts['scheme'] ?? '')) || !is_string($parts['host'] ?? null)) {
            throw CredentialUnavailableException::because($secret->id, 'the token URL is not an https URL');
        }

        if (!HostMatcher::matches($parts['host'], $secret->allowedHosts)) {
            throw CredentialUnavailableException::because($secret->id, 'the token URL host is not among the allowed hosts');
        }

        $token = $this->cache->get($secret);

        if (null === $token) {
            $token = $this->fetchToken($secret, $tokenUrl, $clientId, $clientSecret, self::CLIENT_AUTH_BASIC === $clientAuth);
        }

        return new HttpAuthorization(
            headers: ['Authorization' => 'Bearer ' . $token],
            secrets: [$clientSecret, $token, base64_encode($clientId . ':' . $clientSecret)],
        );
    }

    private function fetchToken(CredentialSecret $secret, string $tokenUrl, string $clientId, string $clientSecret, bool $basic): string
    {
        $form  = ['grant_type' => 'client_credentials'];
        $scope = $secret->setting('scope');

        if (null !== $scope && '' !== mb_trim($scope)) {
            $form['scope'] = mb_trim($scope);
        }

        $request = $this->http->request()->withoutRedirecting()->acceptJson()->asForm()->timeout(self::TIMEOUT_SECONDS);

        if ($basic) {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        } else {
            $form['client_id']     = $clientId;
            $form['client_secret'] = $clientSecret;
        }

        try {
            $response = $request->post($tokenUrl, $form);
        } catch (EgressDeniedException) {
            $this->warn($secret, 'refused by the egress guard');

            throw CredentialUnavailableException::because($secret->id, 'the token endpoint was refused by the egress guard');
        } catch (ConnectionException) {
            $this->warn($secret, 'no answer');

            throw CredentialUnavailableException::because($secret->id, 'the token endpoint did not answer');
        }

        if (!$response->successful()) {
            $this->warn($secret, 'status ' . $response->status());

            throw CredentialUnavailableException::because($secret->id, 'the token endpoint answered with status ' . $response->status());
        }

        $accessToken = $response->json('access_token');

        if (!is_string($accessToken) || '' === $accessToken) {
            $this->warn($secret, 'no access token in a ' . $response->status() . ' answer');

            throw CredentialUnavailableException::because($secret->id, 'the token endpoint gave no access token');
        }

        $expiresIn = $response->json('expires_in');

        $this->cache->put($secret, $accessToken, is_numeric($expiresIn) ? (int) $expiresIn : null);

        return $accessToken;
    }

    private function warn(CredentialSecret $secret, string $what): void
    {
        $this->logger->warning('OAuth2 token request failed: ' . $what, [
            'tenant_id'     => $secret->tenantId,
            'credential_id' => $secret->id,
        ]);
    }
}
