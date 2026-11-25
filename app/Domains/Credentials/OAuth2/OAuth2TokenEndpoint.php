<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

use App\Domains\Credentials\HostMatcher;
use App\Domains\Flow\Call\Egress\EgressDeniedException;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * The requests every OAuth2 credential type makes to a token endpoint: client credentials, the authorization code
 * exchange and the refresh.
 *
 * The endpoint is a tenant-chosen URL, so it must be https and on one of the credential's allowed hosts (the client
 * secret and the refresh token are only ever sent where the admin said), is reached through the egress guard and
 * never followed through a redirect. A failure is reported by its status and the provider's `error` code alone, never
 * by a response body.
 */
final readonly class OAuth2TokenEndpoint
{
    public const string CLIENT_AUTH_BASIC = 'basic';

    public const string CLIENT_AUTH_BODY = 'body';

    /** The least time a token request is sent with; less than this left of a caller's limit sends nothing. */
    public const float MIN_TIMEOUT_SECONDS = 1.0;

    private const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private GuardedHttpClient $http,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * A provider's `error` code when it looks like one (RFC 6749 codes are lower case with underscores); anything else
     * may be free text and is dropped.
     */
    public static function errorCode(mixed $value): ?string
    {
        return is_string($value) && 1 === preg_match('/^[a-z_]{1,64}$/', $value) ? $value : null;
    }

    /**
     * The token URL, once it is known to be an https URL on an allowed host.
     *
     * @throws CredentialUnavailableException when it is missing, not https or off the allowed hosts
     */
    public function checkedUrl(CredentialSecret $secret): string
    {
        $tokenUrl = $secret->setting('token_url');

        if (null === $tokenUrl || '' === $tokenUrl) {
            throw CredentialUnavailableException::because($secret->id, 'the token URL is missing');
        }

        $parts = parse_url($tokenUrl);

        if (!is_array($parts) || 'https' !== mb_strtolower((string) ($parts['scheme'] ?? '')) || !is_string($parts['host'] ?? null)) {
            throw CredentialUnavailableException::because($secret->id, 'the token URL is not an https URL');
        }

        if (!HostMatcher::matches($parts['host'], $secret->allowedHosts)) {
            throw CredentialUnavailableException::because($secret->id, 'the token URL host is not among the allowed hosts');
        }

        return $tokenUrl;
    }

    /**
     * Posts the form with the client's credentials, in the Authorization header (`basic`) or in the body.
     *
     * @param  array<string, string>  $form  the grant's own parameters
     * @param  float|null  $timeoutSeconds  what the caller has left to spend (a call's timeout); the request takes the
     *                                      smaller of it and its own limit. Null for no caller limit
     *
     * @throws CredentialUnavailableException when the token URL, the client or its authentication method is not usable,
     *                                        or less than {@see self::MIN_TIMEOUT_SECONDS} is left of the caller's limit
     * @throws OAuth2TokenRequestFailedException when the endpoint gave no token
     */
    public function request(CredentialSecret $secret, #[SensitiveParameter] array $form, ?float $timeoutSeconds = null): OAuth2TokenResponse
    {
        $tokenUrl     = $this->checkedUrl($secret);
        $clientId     = $secret->setting('client_id');
        $clientSecret = $secret->value('client_secret');
        $clientAuth   = $secret->setting('client_auth') ?? self::CLIENT_AUTH_BASIC;

        if (null === $clientId || '' === $clientId || null === $clientSecret || '' === $clientSecret) {
            throw CredentialUnavailableException::because($secret->id, 'the token URL, the client id or the client secret is missing');
        }

        if (!in_array($clientAuth, [self::CLIENT_AUTH_BASIC, self::CLIENT_AUTH_BODY], true)) {
            throw CredentialUnavailableException::because($secret->id, 'the client authentication method is not valid');
        }

        if (null !== $timeoutSeconds && $timeoutSeconds < self::MIN_TIMEOUT_SECONDS) {
            $this->warn($secret, 'no time left of the call');

            throw CredentialUnavailableException::because($secret->id, 'no time is left for the token request');
        }

        $timeout = null === $timeoutSeconds ? self::TIMEOUT_SECONDS : min((float) self::TIMEOUT_SECONDS, $timeoutSeconds);
        $request = $this->http->request()->withoutRedirecting()->acceptJson()->asForm()->timeout($timeout);

        if (self::CLIENT_AUTH_BASIC === $clientAuth) {
            $request = $request->withBasicAuth($clientId, $clientSecret);
        } else {
            $form['client_id']     = $clientId;
            $form['client_secret'] = $clientSecret;
        }

        try {
            $response = $request->post($tokenUrl, $form);
        } catch (EgressDeniedException) {
            $this->warn($secret, 'refused by the egress guard');

            throw new OAuth2TokenRequestFailedException('the token endpoint was refused by the egress guard');
        } catch (ConnectionException) {
            $this->warn($secret, 'no answer');

            throw new OAuth2TokenRequestFailedException('the token endpoint did not answer');
        }

        if (!$response->successful()) {
            $errorCode = self::errorCode($response->json('error'));

            $this->warn($secret, 'status ' . $response->status() . (null === $errorCode ? '' : ' ' . $errorCode));

            throw new OAuth2TokenRequestFailedException('the token endpoint answered with status ' . $response->status(), $response->status(), $errorCode);
        }

        $accessToken = $response->json('access_token');

        if (!is_string($accessToken) || '' === $accessToken) {
            $this->warn($secret, 'no access token in a ' . $response->status() . ' answer');

            throw new OAuth2TokenRequestFailedException('the token endpoint gave no access token', $response->status());
        }

        $refreshToken = $response->json('refresh_token');
        $scope        = $response->json('scope');

        return new OAuth2TokenResponse(
            accessToken: $accessToken,
            refreshToken: is_string($refreshToken) && '' !== $refreshToken ? $refreshToken : null,
            expiresIn: self::seconds($response->json('expires_in')),
            refreshExpiresIn: self::seconds($response->json('refresh_token_expires_in') ?? $response->json('refresh_expires_in')),
            scope: is_string($scope) && '' !== $scope ? mb_substr($scope, 0, 1024) : null,
        );
    }

    private static function seconds(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function warn(CredentialSecret $secret, string $what): void
    {
        $this->logger->warning('OAuth2 token request failed: ' . $what, [
            'tenant_id'     => $secret->tenantId,
            'credential_id' => $secret->id,
        ]);
    }
}
