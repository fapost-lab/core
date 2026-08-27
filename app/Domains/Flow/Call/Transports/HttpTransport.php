<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Transports;

use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Fapost\Foundation\Flow\Call\CallResult;
use Fapost\Foundation\Flow\Call\CallTransportInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use JsonException;

/**
 * Built-in HTTP transport for the {@code call} node.
 *
 * Target shape: "{METHOD} {URL}". Supported methods: GET, POST, PUT, DELETE, PATCH.
 *
 * Parameter prefixes:
 *  - body.*       → JSON request body
 *  - query.*      → URL query string (also supported for GET)
 *  - headers.*    → request headers
 *  - auth.bearer  → Bearer token (Authorization: Bearer …)
 *  - auth.basic.username / auth.basic.password → Basic auth
 *
 * Options:
 *  - timeout       (int, seconds; default 10)
 *  - headers       (array; merged with parameter headers)
 *  - success_when  ("2xx" | "any_response" | "2xx_or_4xx"; default "2xx")
 *
 * Idempotency-Key header is set automatically from {@code $context->idempotencyKey}
 * unless the caller supplied one explicitly. Transport-level failures (DNS, timeout,
 * TLS) always end in error regardless of success_when.
 */
final readonly class HttpTransport implements CallTransportInterface
{
    public const string ID = 'http';

    public function __construct(
        private HttpFactory $http,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function execute(CallRequest $request, CallContext $context): CallResult
    {
        [$method, $url] = $this->parseTarget($request->target);

        if (null === $method) {
            return CallResult::error('invalid_target', metadata: ['target' => $request->target]);
        }

        [$body, $query, $headers, $bearer, $basic] = $this->bucketParameters($request->parameters);

        $optionHeaders = is_array($request->options['headers'] ?? null) ? $request->options['headers'] : [];
        $headers       = array_replace($optionHeaders, $headers);
        $headers['Idempotency-Key'] ??= $context->idempotencyKey;

        $timeout     = (int) ($request->options['timeout'] ?? 10);
        $successWhen = (string) ($request->options['success_when'] ?? '2xx');

        // Raw body wins over bucketed body.* params: when present, the author
        // supplies a pre-built (templated) JSON string for nested/array shapes
        // the flat body.* convention can't express.
        $rawBody = is_string($request->options['body_raw'] ?? null) && '' !== mb_trim($request->options['body_raw'])
            ? $request->options['body_raw']
            : null;

        $client = $this->configureClient($this->http->withHeaders($headers)->timeout($timeout), $bearer, $basic);

        if (null !== $rawBody) {
            $client = $client->withBody($rawBody, 'application/json');
            $body   = [];
        }

        try {
            $response = match ($method) {
                'GET'    => $client->get($url, $query),
                'DELETE' => $client->delete($url, [] === $body ? $query : $body),
                'POST'   => $client->post($this->appendQuery($url, $query), $body),
                'PUT'    => $client->put($this->appendQuery($url, $query), $body),
                'PATCH'  => $client->patch($this->appendQuery($url, $query), $body),
                default  => null,
            };
        } catch (ConnectionException $exception) {
            return CallResult::error(
                'transport_failure',
                metadata: ['exception' => $exception->getMessage()],
            );
        }

        if (null === $response) {
            return CallResult::error('invalid_method', metadata: ['method' => $method]);
        }

        $statusCode = $response->status();
        $payload    = $this->parseBody($response->header('Content-Type'), $response->body());
        $metadata   = ['status_code' => $statusCode, 'headers' => $this->flattenHeaders($response->headers())];
        $isSuccess  = $this->matchesSuccessPolicy($statusCode, $successWhen);

        if (! $isSuccess) {
            $code = $statusCode >= 500 ? 'http_5xx' : ($statusCode >= 400 ? 'http_4xx' : 'http_other');

            return CallResult::error($code, $payload, $metadata);
        }

        return CallResult::ok($payload, $metadata);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function parseTarget(string $target): array
    {
        $parts = preg_split('/\s+/', mb_trim($target), 2);

        if (! is_array($parts) || 2 !== count($parts)) {
            return [null, ''];
        }

        $method = mb_strtoupper($parts[0]);
        $url    = $parts[1];

        if (! in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return [null, $url];
        }

        return [$method, $url];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: ?string, 4: array{username?: string, password?: string}}
     */
    private function bucketParameters(array $parameters): array
    {
        $body    = [];
        $query   = [];
        $headers = [];
        $bearer  = null;
        $basic   = [];

        foreach ($parameters as $key => $value) {
            if (str_starts_with($key, 'body.')) {
                $body[mb_substr($key, 5)] = $value;
            } elseif (str_starts_with($key, 'query.')) {
                $query[mb_substr($key, 6)] = $value;
            } elseif (str_starts_with($key, 'headers.')) {
                $headers[mb_substr($key, 8)] = (string) $value;
            } elseif ('auth.bearer' === $key) {
                $bearer = (string) $value;
            } elseif ('auth.basic.username' === $key) {
                $basic['username'] = (string) $value;
            } elseif ('auth.basic.password' === $key) {
                $basic['password'] = (string) $value;
            }
        }

        return [$body, $query, $headers, $bearer, $basic];
    }

    /**
     * @param  array{username?: string, password?: string}  $basic
     */
    private function configureClient(PendingRequest $client, ?string $bearer, array $basic): PendingRequest
    {
        if (null !== $bearer && '' !== $bearer) {
            return $client->withToken($bearer);
        }

        if (isset($basic['username'], $basic['password'])) {
            return $client->withBasicAuth($basic['username'], $basic['password']);
        }

        return $client;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function appendQuery(string $url, array $query): string
    {
        if ([] === $query) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($query);
    }

    /**
     * @return array<int|string, mixed>|string
     */
    private function parseBody(?string $contentType, string $body): array|string
    {
        if (null !== $contentType && str_contains(mb_strtolower($contentType), 'application/json')) {
            try {
                /** @var array<int|string, mixed> $decoded */
                $decoded = (array) json_decode($body, true, flags: JSON_THROW_ON_ERROR);

                return $decoded;
            } catch (JsonException) {
                // fall through to raw string
            }
        }

        return $body;
    }

    /**
     * Collapse Laravel's `array<string, list<string>>` header bag into a flat
     * `array<string, string>` so `result_mapping` / `{{call.last.headers.X}}`
     * can address a header by name without an index.
     *
     * @param  array<string, list<string>>  $headers
     *
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            $flat[$name] = is_array($values) ? implode(', ', $values) : (string) $values;
        }

        return $flat;
    }

    private function matchesSuccessPolicy(int $statusCode, string $policy): bool
    {
        return match ($policy) {
            'any_response' => true,
            '2xx_or_4xx'   => ($statusCode >= 200 && $statusCode < 300) || ($statusCode >= 400 && $statusCode < 500),
            default        => $statusCode >= 200 && $statusCode < 300,
        };
    }
}
