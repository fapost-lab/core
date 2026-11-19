<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\Types\OAuth2ClientCredentialsType;
use App\Domains\Flow\Call\Egress\EgressGuardMiddleware;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use App\Domains\Flow\Call\Transports\HttpTransport;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Fapost\Foundation\Flow\Call\CallResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Domains\Credentials\CredentialsTestCase;

/**
 * The http transport with a saved credential: how it is applied, where it may be sent, and what never leaves it.
 */
final class HttpTransportCredentialTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Booting the database may already have built the guard with the real DNS resolver; rebuild it on the fake.
        foreach ([HttpTransport::class, GuardedHttpClient::class, EgressGuardMiddleware::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    public function test_the_credential_headers_replace_the_authors_even_in_another_case_and_inline_auth_is_ignored(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);
        $secret = $this->secretOf($this->credential());

        $result = $this->execute(new CallRequest(
            target: 'POST https://api.example.com/items',
            parameters: ['headers.authorization' => 'Bearer author-token', 'auth.bearer' => 'inline-token', 'body.a' => '1'],
            credential: $secret,
        ));

        $this->assertTrue($result->success);
        Http::assertSent(static fn (Request $request): bool => ['Bearer ' . self::SECRET] === $request->header('Authorization')
            && 1 === count($request->header('Authorization')));
    }

    public function test_the_authors_routing_headers_are_dropped_so_the_request_lands_on_the_allowed_host(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);
        $secret = $this->secretOf($this->credential());

        $result = $this->execute(new CallRequest(
            target: 'GET https://api.example.com/items',
            parameters: ['headers.HOST' => 'attacker.example', 'headers.X-Forwarded-Host' => 'attacker.example'],
            options: ['headers' => ['host' => 'attacker.example', 'forwarded' => 'host=attacker.example']],
            credential: $secret,
        ));

        $this->assertTrue($result->success);
        Http::assertSent(static fn (Request $request): bool => ['api.example.com'] === $request->header('Host')
            && [] === $request->header('X-Forwarded-Host')
            && [] === $request->header('Forwarded'));
    }

    public function test_without_a_credential_the_authors_host_header_is_kept(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $this->execute(new CallRequest(target: 'GET https://api.example.com/items', parameters: ['headers.Host' => 'virtual.example']));

        Http::assertSent(static fn (Request $request): bool => ['virtual.example'] === $request->header('Host'));
    }

    public function test_the_credential_query_replaces_the_authors_and_reaches_a_delete_with_a_body(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);
        $secret = $this->secretOf($this->credential([
            'type'     => 'api_key_query',
            'settings' => ['parameter' => 'api_key'],
            'secret'   => ['key' => self::SECRET],
        ]));

        $this->execute(new CallRequest('GET https://api.example.com/a', ['query.api_key' => 'author', 'query.page' => '2'], credential: $secret));
        $this->execute(new CallRequest('DELETE https://api.example.com/b', ['body.id' => '5'], credential: $secret));

        Http::assertSent(static fn (Request $request): bool => 'GET' === $request->method()
            && str_contains($request->url(), 'api_key=' . self::SECRET)
            && str_contains($request->url(), 'page=2')
            && ! str_contains($request->url(), 'author'));
        Http::assertSent(static fn (Request $request): bool => 'DELETE' === $request->method()
            && str_contains($request->url(), 'api_key=' . self::SECRET));
    }

    public function test_the_secret_echoed_in_the_body_and_a_header_is_masked(): void
    {
        Http::fake(['api.example.com/*' => Http::response(
            ['you_sent' => 'Bearer ' . self::SECRET, 'nested' => ['x' => self::SECRET]],
            200,
            ['Content-Type' => 'application/json', 'X-Echo' => self::SECRET],
        )]);

        $result = $this->execute(new CallRequest('GET https://api.example.com/echo', credential: $this->secretOf($this->credential())));

        $this->assertTrue($result->success);
        $this->assertSame(['you_sent' => 'Bearer ***', 'nested' => ['x' => '***']], $result->payload);
        $this->assertSame('***', $result->metadata['headers']['X-Echo']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($result, JSON_THROW_ON_ERROR) ?: '');
    }

    public function test_an_error_response_and_a_connection_exception_with_the_key_in_the_url_are_masked(): void
    {
        $secret = $this->secretOf($this->credential([
            'type'     => 'api_key_query',
            'settings' => ['parameter' => 'api_key'],
            'secret'   => ['key' => self::SECRET],
        ]));

        Http::fake(['api.example.com/err*' => Http::response('bad key ' . self::SECRET, 400)]);
        $http = $this->execute(new CallRequest('GET https://api.example.com/err', credential: $secret));

        $this->assertSame('http_4xx', $http->errorCode);
        $this->assertSame('bad key ***', $http->payload);

        Http::fake(['api.example.com/down*' => static function (Request $request): never {
            throw new ConnectionException('cURL error 28: timed out for ' . $request->url());
        }]);
        $down = $this->execute(new CallRequest('GET https://api.example.com/down', credential: $secret));

        $this->assertSame('transport_failure', $down->errorCode);
        $this->assertStringContainsString('api_key=***', $down->metadata['exception']);
        $this->assertStringNotContainsString(self::SECRET, $down->metadata['exception']);
    }

    public function test_a_host_outside_the_list_is_refused_without_a_connection(): void
    {
        Http::fake();
        $secret = $this->secretOf($this->credential(['allowed_hosts' => ['api.example.com']]));

        foreach (['GET https://evil.example.net/x?k=1', 'GET https://api.example.com.evil.net/x', 'GET https://sub.api.example.com/x'] as $target) {
            $result = $this->execute(new CallRequest($target, credential: $secret));

            $this->assertFalse($result->success);
            $this->assertSame('credential_host_denied', $result->errorCode);
            $this->assertArrayNotHasKey('headers', $result->metadata);
            $this->assertStringNotContainsString('?k=1', json_encode($result->metadata, JSON_THROW_ON_ERROR));
        }

        Http::assertNothingSent();
    }

    public function test_a_wildcard_host_matches_a_subdomain(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $secret = $this->secretOf($this->credential(['allowed_hosts' => ['*.example.com']]));

        $this->assertTrue($this->execute(new CallRequest('GET https://eu.api.example.com/x', credential: $secret))->success);
    }

    public function test_a_redirect_is_not_followed_and_the_credential_goes_nowhere_else(): void
    {
        Http::fake([
            'api.example.com/start' => Http::response('', 302, ['Location' => 'https://api.example.com/next']),
            'api.example.com/next'  => Http::response('followed', 200),
        ]);

        $result = $this->execute(new CallRequest('GET https://api.example.com/start', credential: $this->secretOf($this->credential())));

        $this->assertFalse($result->success);
        $this->assertSame(302, $result->metadata['status_code']);
        Http::assertSentCount(1);
    }

    public function test_without_a_credential_a_redirect_is_still_followed(): void
    {
        Http::fake([
            'api.example.com/start' => Http::response('', 302, ['Location' => 'https://api.example.com/next']),
            'api.example.com/next'  => Http::response('followed', 200),
        ]);

        $result = $this->execute(new CallRequest('GET https://api.example.com/start'));

        $this->assertTrue($result->success);
        Http::assertSentCount(2);
    }

    public function test_an_unknown_type_and_a_type_that_cannot_authorize_are_unavailable(): void
    {
        Http::fake();

        $unknown = new CredentialSecret($this->tenantId, 'id-1', 'martian', 'v1', [], ['api.example.com'], ['token' => self::SECRET]);
        $empty   = $this->secretOf($this->credential(['secret' => ['token' => '']]));

        foreach ([$unknown, $empty] as $secret) {
            $result = $this->execute(new CallRequest('GET https://api.example.com/x', credential: $secret));

            $this->assertSame('credential_unavailable', $result->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_a_delete_without_a_body_carries_the_credential_query_in_the_url(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);
        $secret = $this->secretOf($this->credential([
            'type'     => 'api_key_query',
            'settings' => ['parameter' => 'api_key'],
            'secret'   => ['key' => self::SECRET],
        ]));

        $result = $this->execute(new CallRequest('DELETE https://api.example.com/b', ['query.api_key' => 'author'], credential: $secret));

        $this->assertTrue($result->success);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'api_key=' . self::SECRET)
            && ! str_contains($request->body(), self::SECRET)
            && ! str_contains($request->url() . $request->body(), 'author'));
    }

    public function test_a_token_request_that_spends_the_whole_timeout_sends_nothing_to_the_target(): void
    {
        Http::fake([
            'auth.example.com/*' => static function () {
                usleep(1_100_000);

                return Http::response(['access_token' => 'tok-slow1234', 'expires_in' => 3600]);
            },
            'api.example.com/*' => Http::response([], 200),
        ]);
        $secret = $this->secretOf($this->credential([
            'type'          => OAuth2ClientCredentialsType::ID,
            'allowed_hosts' => ['api.example.com', 'auth.example.com'],
            'settings'      => ['token_url' => 'https://auth.example.com/oauth/token', 'client_id' => 'client-1', 'client_auth' => 'basic'],
            'secret'        => ['client_secret' => self::SECRET],
        ]));

        $result = $this->execute(new CallRequest('GET https://api.example.com/x', options: ['timeout' => 1], credential: $secret));

        $this->assertSame('credential_unavailable', $result->errorCode);
        $this->assertSame(['reason' => 'timeout'], $result->metadata);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'api.example.com'));
    }

    public function test_a_401_from_an_oauth2_target_drops_the_cached_token(): void
    {
        Http::fake([
            'auth.example.com/*' => Http::response(['access_token' => 'tok-abc123', 'expires_in' => 3600]),
            'api.example.com/*'  => Http::response(['error' => 'expired tok-abc123'], 401),
        ]);
        $secret = $this->secretOf($this->credential([
            'type'          => OAuth2ClientCredentialsType::ID,
            'allowed_hosts' => ['api.example.com', 'auth.example.com'],
            'settings'      => ['token_url' => 'https://auth.example.com/oauth/token', 'client_id' => 'client-1', 'client_auth' => 'basic'],
            'secret'        => ['client_secret' => self::SECRET],
        ]));

        $result = $this->execute(new CallRequest('GET https://api.example.com/x', credential: $secret));

        $this->assertSame('http_4xx', $result->errorCode);
        $this->assertSame(['error' => 'expired ***'], $result->payload);
        $this->assertSame([], $this->redis->values);
    }

    public function test_a_401_from_a_bearer_target_leaves_the_token_cache_alone(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 401)]);
        $secret                                                                                  = $this->secretOf($this->credential());
        $this->redis->values['credentials:oauth2:' . $this->tenantId . ':' . $secret->id . ':v'] = 'kept';

        $this->execute(new CallRequest('GET https://api.example.com/x', credential: $secret));

        $this->assertCount(1, $this->redis->values);
    }

    private function execute(CallRequest $request): CallResult
    {
        return $this->app->make(HttpTransport::class)->execute($request, new CallContext(
            tenantId: $this->tenantId,
            contactId: 'contact-1',
            sessionId: 'session-1',
            nodeId: 'call-1',
            idempotencyKey: 'key-1',
        ));
    }

    private function secretOf(Credential $credential): CredentialSecret
    {
        return $this->app->make(CredentialStoreInterface::class)->secret($credential->id);
    }
}
