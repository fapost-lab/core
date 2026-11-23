<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Transports;

use App\Domains\Credentials\Contracts\RejectedTokenAwareInterface;
use App\Domains\Credentials\CredentialTypeRegistry;
use App\Domains\Credentials\HostMatcher;
use App\Domains\Credentials\SecretRedactor;
use App\Domains\Flow\Call\CallAttempt;
use App\Domains\Flow\Call\CallSuccessStatuses;
use App\Domains\Flow\Call\Egress\EgressDeniedException;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\DTO\HttpAuthorization;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Fapost\Foundation\Flow\Call\CallResult;
use Fapost\Foundation\Flow\Call\CallTransportInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;

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
 * Idempotency-Key header is set automatically from {@code $context->idempotencyKey}, and
 * {@see CallAttempt::HEADER} from the attempt number the call node passes in the options,
 * unless the caller supplied one explicitly. Transport-level failures (DNS, timeout,
 * TLS) always end in error regardless of success_when.
 *
 * Requests go through {@see GuardedHttpClient}: a target on a private, loopback,
 * link-local, reserved or cloud-metadata address, directly, through DNS or through
 * a redirect, ends in the `egress_denied` error code without a connection.
 *
 * A request that carries a saved credential ({@see CallRequest::$credential}) is sent as follows:
 *  - the target host must match the credential's allowed hosts, else `credential_host_denied` and no connection
 *    (nor a token request);
 *  - the credential's type supplies the headers and query, which replace the author's of the same name (headers
 *    compared case-insensitively); the author's `auth.bearer` / `auth.basic.*` are ignored;
 *  - a redirect is not followed, the 3xx is the answer;
 *  - every returned result, error ones and exception messages included (a header value or URL the HTTP client
 *    refuses is `transport_failure`, not a thrown exception), has the credential's secret strings
 *    replaced by `***` ({@see SecretRedactor}), so what the ledger, the state and the logs get is already masked;
 *  - a type that cannot authorize (the OAuth2 endpoint did not give a token, a setting is missing) is
 *    `credential_unavailable`; a 401 from the target tells a type that keeps a token
 *    ({@see RejectedTokenAwareInterface}) to stop using it.
 *
 * The transport is a singleton and keeps no tenant state: the type registry is a singleton of classes that builds a
 * type, with its scoped collaborators, on each `get()`, and the credential travels in the request.
 */
final readonly class HttpTransport implements CallTransportInterface
{
    public const string ID = 'http';

    /**
     * The request timeout when the node sets none; the `call` retry window is sized from it.
     */
    public const int DEFAULT_TIMEOUT_SECONDS = 10;

    /**
     * The least time worth sending the target request with, once authorizing a credential has spent part of the timeout.
     * A short timeout lowers it to half of itself, so a call with a 1 s timeout (or a retry cut to 1 s) is still sent
     * when authorizing took only milliseconds.
     */
    private const float MIN_TARGET_SECONDS = 1.0;

    /**
     * Headers that choose where a request lands behind the target address; an author may not set them on a request
     * that carries a credential.
     *
     * @var list<string>
     */
    private const array ROUTING_HEADERS = ['Host', 'X-Forwarded-Host', 'Forwarded', 'X-Original-Host', 'X-Host'];

    public function __construct(
        private GuardedHttpClient $http,
        private LoggerInterface $logger,
        private CredentialTypeRegistry $credentialTypes,
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

        $credential = $request->credential;

        if (null === $credential) {
            return $this->send($method, $url, $request, $context, null);
        }

        $startedAt     = hrtime(true);
        $authorization = $this->authorize($credential, $url, $context);

        if ($authorization instanceof CallResult) {
            return $authorization;
        }

        // An OAuth2 token request spends the call's own time: the target gets what is left of the timeout, so a cold
        // token cache cannot stretch the node past the window the flow runtime sizes its jobs and locks by.
        $timeout = (int) ($request->options['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        $left    = $timeout - (hrtime(true) - $startedAt) / 1e9;

        if ($timeout > 0 && $left < min(self::MIN_TARGET_SECONDS, $timeout / 2)) {
            $this->logger->warning('Call node credential took the whole timeout to authorize; the request was not sent.', [
                'tenant_id'     => $context->tenantId,
                'node_id'       => $context->nodeId,
                'credential_id' => $credential->id,
            ]);

            return CallResult::error('credential_unavailable', metadata: ['reason' => 'timeout']);
        }

        try {
            $result = $this->send($method, $url, $request, $context, $authorization, $timeout > 0 ? $left : null);
        } catch (InvalidArgumentException $exception) {
            // The HTTP client refuses a header value or a URL it cannot send by quoting it whole, and the value may be
            // the credential's (an IdP may hand back any token): the message leaves here masked, as a result, never
            // as an exception a worker log or the builder would print.
            $result = CallResult::error('transport_failure', metadata: ['exception' => $exception->getMessage()]);
        }

        if (401 === ($result->metadata['status_code'] ?? null)) {
            // The registry builds the type for this call, so its tenant-bound storage is this job's.
            $type = $this->credentialTypes->get($credential->type);

            if ($type instanceof RejectedTokenAwareInterface) {
                $type->tokenRejected($credential);
            }
        }

        return $this->masked($result, $authorization);
    }

    /**
     * Checks the target host against the credential's allowed hosts and asks its type for the authorization.
     * A refusal comes back as the result to return; nothing is sent, and no token is requested for a denied host.
     */
    private function authorize(CredentialSecret $credential, string $url, CallContext $context): HttpAuthorization|CallResult
    {
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? $host : '';

        if (! HostMatcher::matches($host, $credential->allowedHosts)) {
            // The URL path and query are left out on purpose: they carry tokens.
            $this->logger->warning('Call node request refused: the host is not allowed for the credential.', [
                'tenant_id'     => $context->tenantId,
                'node_id'       => $context->nodeId,
                'host'          => $host,
                'credential_id' => $credential->id,
            ]);

            return CallResult::error('credential_host_denied', metadata: ['host' => $host]);
        }

        try {
            return $this->credentialTypes->get($credential->type)->authorize($credential);
        } catch (InvalidArgumentException|CredentialUnavailableException $exception) {
            $this->logger->warning('Call node credential cannot be applied.', [
                'tenant_id'     => $context->tenantId,
                'node_id'       => $context->nodeId,
                'credential_id' => $credential->id,
                'type'          => $credential->type,
                'exception'     => $exception::class,
            ]);

            return CallResult::error('credential_unavailable');
        }
    }

    /**
     * Replaces the authorization's secret strings in the whole result: payload, metadata (headers, exception
     * messages) and their keys. The error code is a fixed word and stays as it is.
     */
    private function masked(CallResult $result, HttpAuthorization $authorization): CallResult
    {
        $secrets = $authorization->secrets();

        /** @var array<string, mixed> $metadata */
        $metadata = SecretRedactor::redact($result->metadata, $secrets);

        return new CallResult($result->success, SecretRedactor::redact($result->payload, $secrets), $result->errorCode, $metadata);
    }

    /**
     * @param  float|null  $timeoutLeft  seconds left of the call's timeout after the credential was authorized; null
     *                                   to use the timeout option as it is
     */
    private function send(string $method, string $url, CallRequest $request, CallContext $context, ?HttpAuthorization $authorization, ?float $timeoutLeft = null): CallResult
    {
        [$body, $query, $headers, $bearer, $basic] = $this->bucketParameters($request->parameters);

        $optionHeaders   = is_array($request->options['headers'] ?? null) ? $request->options['headers'] : [];
        $headers         = array_replace($optionHeaders, $headers);
        $credentialQuery = [];

        if ($authorization instanceof HttpAuthorization) {
            // The author's routing headers go too: a shared ingress that routes by Host would hand the credential to
            // another site behind the allowed host.
            $headers          = $this->withoutNamed($headers, [...array_keys($authorization->headers()), ...self::ROUTING_HEADERS]);
            $headers          = [...$headers, ...$authorization->headers()];
            $credentialQuery  = $authorization->query();
            $query            = [...array_diff_key($query, $credentialQuery), ...$credentialQuery];
            [$bearer, $basic] = [null, []];
        }

        $headers['Idempotency-Key'] ??= $context->idempotencyKey;

        $attempt = $request->options[CallAttempt::OPTION] ?? null;

        if (is_int($attempt) && $attempt > 0) {
            $headers[CallAttempt::HEADER] ??= (string) $attempt;
        }

        $timeout     = (int) ($request->options['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        $successWhen = (string) ($request->options['success_when'] ?? '2xx');

        // Raw body wins over bucketed body.* params: when present, the author
        // supplies a pre-built (templated) JSON string for nested/array shapes
        // the flat body.* convention can't express.
        $rawBody = is_string($request->options['body_raw'] ?? null) && '' !== mb_trim($request->options['body_raw'])
            ? $request->options['body_raw']
            : null;

        $client = $this->configureClient($this->http->request()->withHeaders($headers)->timeout($timeoutLeft ?? $timeout), $bearer, $basic);

        if ($authorization instanceof HttpAuthorization) {
            // A credential is never carried to another place by a redirect.
            $client = $client->withoutRedirecting();
        }

        if (null !== $rawBody) {
            $client = $client->withBody($rawBody, 'application/json');
            $body   = [];
        }

        try {
            $response = match ($method) {
                'GET' => $client->get($url, $query),
                // With a body the query is not sent, as before; the credential's query is the exception, it must reach the target.
                // Without a body the author's query travels as the request data, as before; the credential's query
                // always goes in the URL, where the target reads it.
                'DELETE' => [] === $body
                    ? $client->delete($this->appendQuery($url, $credentialQuery), array_diff_key($query, $credentialQuery))
                    : $client->delete($this->appendQuery($url, $credentialQuery), $body),
                'POST'  => $client->post($this->appendQuery($url, $query), $body),
                'PUT'   => $client->put($this->appendQuery($url, $query), $body),
                'PATCH' => $client->patch($this->appendQuery($url, $query), $body),
                default => null,
            };
        } catch (EgressDeniedException $exception) {
            // The URL path and query are left out on purpose: they carry tokens.
            $this->logger->warning('Call node request refused by the egress guard.', [
                'tenant_id'  => $context->tenantId,
                'session_id' => $context->sessionId,
                'node_id'    => $context->nodeId,
                'host'       => $exception->host,
                'reason'     => $exception->reason,
                'addresses'  => $exception->addresses,
            ]);

            return CallResult::error(
                'egress_denied',
                metadata: ['host' => $exception->host, 'reason' => $exception->reason],
            );
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
            return CallResult::error(CallSuccessStatuses::errorCodeFor($statusCode), $payload, $metadata);
        }

        return CallResult::ok($payload, $metadata);
    }

    /**
     * The headers whose names differ from every given name, compared case-insensitively.
     *
     * @param  array<string, mixed>  $headers
     * @param  list<string>  $names
     *
     * @return array<string, mixed>
     */
    private function withoutNamed(array $headers, array $names): array
    {
        $names = array_map(mb_strtolower(...), $names);

        return array_filter($headers, static fn (int|string $name): bool => ! in_array(mb_strtolower((string) $name), $names, true), ARRAY_FILTER_USE_KEY);
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
