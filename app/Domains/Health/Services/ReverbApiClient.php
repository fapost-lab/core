<?php

declare(strict_types=1);

namespace App\Domains\Health\Services;

use App\Domains\Health\Exceptions\ReverbApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use SensitiveParameter;

/**
 * Reads Reverb's HTTP API, the Pusher REST API it implements.
 *
 * Signed by hand rather than through the Pusher SDK that the broadcasting driver wraps: the SDK's HTTP client cannot be
 * given a per-call timeout through Laravel's configuration, and a health check that can hang for the length of a default
 * Guzzle request is worse than none. The signature is an HMAC of the method, path and query under the app secret, so
 * the secret itself never leaves this method: it is not sent, not logged and not part of any exception message.
 */
final readonly class ReverbApiClient
{
    private const int TIMEOUT_SECONDS = 3;

    private const int CONNECT_TIMEOUT_SECONDS = 2;

    public function __construct(private HttpFactory $http)
    {
    }

    /**
     * Number of open connections across the app.
     *
     * @throws ReverbApiException
     */
    public function connections(
        string $scheme,
        string $host,
        int $port,
        string $appId,
        string $key,
        #[SensitiveParameter]
        string $secret,
    ): int {
        $body = $this->get($scheme, $host, $port, $appId, $key, $secret, 'connections');

        return is_numeric($body['connections'] ?? null) ? (int) $body['connections'] : throw ReverbApiException::malformed();
    }

    /**
     * Names of the channels with at least one subscriber.
     *
     * @return list<string>
     *
     * @throws ReverbApiException
     */
    public function channels(
        string $scheme,
        string $host,
        int $port,
        string $appId,
        string $key,
        #[SensitiveParameter]
        string $secret,
    ): array {
        $body = $this->get($scheme, $host, $port, $appId, $key, $secret, 'channels');

        // An empty list is encoded as `[]` and a populated one as an object, so both shapes are valid.
        if (! array_key_exists('channels', $body) || ! is_array($body['channels'])) {
            throw ReverbApiException::malformed();
        }

        return array_map(strval(...), array_keys($body['channels']));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReverbApiException
     */
    private function get(
        string $scheme,
        string $host,
        int $port,
        string $appId,
        string $key,
        #[SensitiveParameter]
        string $secret,
        string $resource,
    ): array {
        $path  = "/apps/{$appId}/{$resource}";
        $query = http_build_query([
            'auth_key'       => $key,
            'auth_timestamp' => time(),
            'auth_version'   => '1.0',
        ]);
        $signature = hash_hmac('sha256', "GET\n{$path}\n{$query}", $secret);

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get("{$scheme}://{$host}:{$port}{$path}?{$query}&auth_signature={$signature}");
        } catch (ConnectionException) {
            throw ReverbApiException::unreachable();
        }

        if (! $response->successful()) {
            throw ReverbApiException::status($response->status());
        }

        $body = $response->json();

        return is_array($body) ? $body : throw ReverbApiException::malformed();
    }
}
