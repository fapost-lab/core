<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Credentials;

use App\Domains\Credentials\OAuth2\OAuth2TokenEndpoint;
use App\Domains\Credentials\OAuth2TokenCache;
use App\Domains\Credentials\RedisKeyPurger;
use App\Domains\Credentials\Types\OAuth2ClientCredentialsType;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\Support\InMemoryRedis;
use Tests\TestCase;

final class OAuth2ClientCredentialsTypeTest extends TestCase
{
    private const string TOKEN_URL = 'https://auth.example.com/oauth/token';

    private const string CLIENT_SECRET = 'client-secret-value';

    private InMemoryRedis $redis;

    private OAuth2TokenCache $cache;

    private AbstractLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.redis.options.prefix' => '']);

        $this->redis  = new InMemoryRedis();
        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $this->cache = new OAuth2TokenCache(
            $this->redis,
            $this->app->make(Encrypter::class),
            new RedisKeyPurger($this->redis, $this->app->make('config')),
            $this->logger,
        );
    }

    public function test_the_token_is_fetched_with_basic_client_auth_and_used_as_a_bearer(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-abc123', 'expires_in' => 3600])]);

        $authorization = $this->type()->authorize($this->secret(['scope' => 'read write']));

        $this->assertSame(['Authorization' => 'Bearer tok-abc123'], $authorization->headers());
        $this->assertSame(
            [self::CLIENT_SECRET, 'tok-abc123', base64_encode('client-1:' . self::CLIENT_SECRET)],
            $authorization->secrets(),
        );

        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => 'POST' === $request->method()
            && self::TOKEN_URL === $request->url()
            && 'client_credentials' === $request['grant_type']
            && 'read write' === $request['scope']
            && ['Basic ' . base64_encode('client-1:' . self::CLIENT_SECRET)] === $request->header('Authorization')
            && !isset($request['client_secret']));
    }

    public function test_the_client_credentials_go_in_the_body_when_the_credential_says_so(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-abc123'])]);

        $this->type()->authorize($this->secret(['client_auth' => 'body']));

        Http::assertSent(static fn (Request $request): bool => 'client-1' === $request['client_id']
            && self::CLIENT_SECRET === $request['client_secret']
            && [] === $request->header('Authorization')
            && !isset($request['scope']));
    }

    public function test_the_token_is_fetched_once_and_stored_encrypted(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-abc123', 'expires_in' => 3600])]);
        $type = $this->type();

        $first  = $type->authorize($this->secret());
        $second = $type->authorize($this->secret());

        $this->assertSame($first->headers(), $second->headers());
        Http::assertSentCount(1);

        $this->assertCount(1, $this->redis->values);
        $key = array_key_first($this->redis->values);
        $this->assertSame('credentials:oauth2:tenant-1:cred-1:v1', $key);
        $this->assertStringNotContainsString('tok-abc123', $this->redis->values[$key]);
        $this->assertSame('tok-abc123', $this->app->make(Encrypter::class)->decryptString($this->redis->values[$key]));
        $this->assertSame(3570, $this->redis->ttls[$key]);
    }

    public function test_a_new_version_of_the_credential_fetches_a_new_token(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-abc123'])]);
        $type = $this->type();

        $type->authorize($this->secret([], 'v1'));
        $type->authorize($this->secret([], 'v2'));

        Http::assertSentCount(2);
    }

    public function test_the_ttl_is_the_lifetime_less_a_margin_with_a_floor_and_a_default(): void
    {
        $secret = $this->secret();

        $this->cache->put($secret, 'tok-1', 100);
        $this->assertSame(70, $this->redis->ttls['credentials:oauth2:tenant-1:cred-1:v1']);

        $this->cache->put($secret, 'tok-1', 10);
        $this->assertSame(1, $this->redis->ttls['credentials:oauth2:tenant-1:cred-1:v1']);

        $this->cache->put($secret, 'tok-1', null);
        $this->assertSame(300, $this->redis->ttls['credentials:oauth2:tenant-1:cred-1:v1']);
    }

    public function test_forget_drops_every_version_of_one_credential_only(): void
    {
        $this->cache->put($this->secret([], 'v1'), 'tok-1', 100);
        $this->cache->put($this->secret([], 'v2'), 'tok-2', 100);
        $this->redis->values['credentials:oauth2:tenant-1:cred-2:v1'] = 'other';
        $this->redis->values['credentials:oauth2:tenant-2:cred-1:v1'] = 'foreign';

        $this->cache->forget('tenant-1', 'cred-1');

        $this->assertSame(['credentials:oauth2:tenant-1:cred-2:v1', 'credentials:oauth2:tenant-2:cred-1:v1'], array_keys($this->redis->values));
    }

    public function test_a_server_error_is_reported_by_its_status_alone(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'oops ' . self::CLIENT_SECRET], 500)]);

        try {
            $this->type()->authorize($this->secret());
            $this->fail('A 500 must make the credential unavailable.');
        } catch (CredentialUnavailableException $exception) {
            $this->assertStringContainsString('status 500', $exception->getMessage());
            $this->assertStringNotContainsString(self::CLIENT_SECRET, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertSame([], $this->redis->values);
        $this->assertLoggedWithoutSecrets('tenant-1', 'cred-1');
    }

    public function test_an_answer_without_an_access_token_is_unavailable(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['token_type' => 'bearer'])]);

        $this->expectException(CredentialUnavailableException::class);

        $this->type()->authorize($this->secret());
    }

    public function test_a_redirect_is_not_followed(): void
    {
        Http::fake([
            self::TOKEN_URL      => Http::response('', 302, ['Location' => 'https://evil.example.net/steal']),
            'evil.example.net/*' => Http::response(['access_token' => 'tok-evil']),
        ]);

        try {
            $this->type()->authorize($this->secret(allowedHosts: ['auth.example.com', 'evil.example.net']));
            $this->fail('A redirect must make the credential unavailable.');
        } catch (CredentialUnavailableException $exception) {
            $this->assertStringContainsString('status 302', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_a_connection_failure_is_unavailable_without_its_message(): void
    {
        Http::fake(static fn () => throw new ConnectionException('cURL error 6: could not resolve auth.example.com with ' . self::CLIENT_SECRET));

        try {
            $this->type()->authorize($this->secret());
            $this->fail('A connection failure must make the credential unavailable.');
        } catch (CredentialUnavailableException $exception) {
            $this->assertStringNotContainsString(self::CLIENT_SECRET, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_a_token_url_off_the_allowed_hosts_is_refused_without_a_request(): void
    {
        Http::fake();

        try {
            $this->type()->authorize($this->secret(allowedHosts: ['api.example.com']));
            $this->fail('A token URL outside the allowed hosts must be refused.');
        } catch (CredentialUnavailableException $exception) {
            $this->assertStringContainsString('allowed hosts', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_token_url_that_is_not_https_is_refused_without_a_request(): void
    {
        Http::fake();

        $this->expectException(CredentialUnavailableException::class);

        try {
            $this->type()->authorize($this->secret(['token_url' => 'http://auth.example.com/oauth/token']));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_missing_client_secret_is_unavailable(): void
    {
        Http::fake();

        $this->expectException(CredentialUnavailableException::class);

        $this->type()->authorize(new CredentialSecret('tenant-1', 'cred-1', 'oauth2_client_credentials', 'v1', [
            'token_url' => self::TOKEN_URL,
            'client_id' => 'client-1',
        ], ['auth.example.com'], []));
    }

    public function test_the_egress_guard_refuses_a_private_token_endpoint(): void
    {
        Http::fake();
        $this->hostResolver->with('auth.example.com', ['10.0.0.5']);

        try {
            $this->type()->authorize($this->secret());
            $this->fail('A private token endpoint must be refused.');
        } catch (CredentialUnavailableException $exception) {
            $this->assertStringContainsString('egress guard', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_unreachable_redis_means_a_fresh_token_for_every_call(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-abc123'])]);
        $this->redis->failing = true;
        $type                 = $this->type();

        $type->authorize($this->secret());
        $type->authorize($this->secret());

        Http::assertSentCount(2);
        $this->assertNotEmpty($this->logger->records);

        foreach ($this->logger->records as $record) {
            $this->assertStringNotContainsString('tok-abc123', json_encode($record, JSON_THROW_ON_ERROR));
        }
    }

    public function test_a_value_in_redis_that_does_not_decrypt_is_a_miss(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'tok-abc123'])]);
        $this->redis->values['credentials:oauth2:tenant-1:cred-1:v1'] = 'not-encrypted';

        $authorization = $this->type()->authorize($this->secret());

        $this->assertSame(['Authorization' => 'Bearer tok-abc123'], $authorization->headers());
        Http::assertSentCount(1);
    }

    public function test_the_tenant_key_prefix_names_the_tenant(): void
    {
        $this->assertSame('credentials:oauth2:tenant-1:', OAuth2TokenCache::tenantKeyPrefix('tenant-1'));
    }

    private function type(): OAuth2ClientCredentialsType
    {
        return new OAuth2ClientCredentialsType(new OAuth2TokenEndpoint($this->app->make(GuardedHttpClient::class), $this->logger), $this->cache);
    }

    /**
     * @param  array<string, string>  $settings  over the defaults
     * @param  list<string>  $allowedHosts
     */
    private function secret(array $settings = [], string $version = 'v1', array $allowedHosts = ['auth.example.com']): CredentialSecret
    {
        return new CredentialSecret(
            'tenant-1',
            'cred-1',
            'oauth2_client_credentials',
            $version,
            [...['token_url' => self::TOKEN_URL, 'client_id' => 'client-1', 'client_auth' => 'basic'], ...$settings],
            $allowedHosts,
            ['client_secret' => self::CLIENT_SECRET],
        );
    }

    private function assertLoggedWithoutSecrets(string $tenantId, string $credentialId): void
    {
        $this->assertNotEmpty($this->logger->records);

        foreach ($this->logger->records as $record) {
            $encoded = json_encode($record, JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString(self::CLIENT_SECRET, $encoded);
            $this->assertSame($tenantId, $record['context']['tenant_id'] ?? null);
            $this->assertSame($credentialId, $record['context']['credential_id'] ?? null);
        }
    }
}
