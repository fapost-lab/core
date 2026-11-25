<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Credentials\CredentialCacheForgetter;
use App\Domains\Credentials\OAuth2TokenCache;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * {@see OAuth2TokenCache} and {@see CredentialCacheForgetter} against a real Redis: the encrypted token, its TTL, and
 * the key purges that delete a version, a credential or a tenant and nothing else.
 */
#[Group('redis')]
final class CredentialTokenCacheRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = 'tenant-' . Str::lower((string) Str::ulid());
    }

    protected function tearDown(): void
    {
        $this->removeKeysOf($this->tenant);
        $this->removeKeysOf('other-' . $this->tenant);

        parent::tearDown();
    }

    public function test_a_token_round_trips_encrypted_and_with_a_ttl(): void
    {
        $secret = $this->secret($this->tenant, 'cred-1', 'v1');

        $this->assertNull($this->cache()->get($secret));

        $this->cache()->put($secret, 'plain-access-token', 3600);

        $this->assertSame('plain-access-token', $this->cache()->get($secret));

        $raw = (string) Redis::connection()->get($this->key($this->tenant, 'cred-1', 'v1'));

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('plain-access-token', $raw);

        $ttl = (int) Redis::connection()->ttl($this->key($this->tenant, 'cred-1', 'v1'));

        $this->assertGreaterThan(3600 - OAuth2TokenCache::EXPIRY_MARGIN_SECONDS - 5, $ttl);
        $this->assertLessThanOrEqual(3600 - OAuth2TokenCache::EXPIRY_MARGIN_SECONDS, $ttl);
    }

    public function test_a_token_without_an_expiry_lives_for_the_default_ttl(): void
    {
        $this->cache()->put($this->secret($this->tenant, 'cred-1', 'v1'), 'tok', null);

        $ttl = (int) Redis::connection()->ttl($this->key($this->tenant, 'cred-1', 'v1'));

        $this->assertGreaterThan(OAuth2TokenCache::DEFAULT_TTL_SECONDS - 5, $ttl);
        $this->assertLessThanOrEqual(OAuth2TokenCache::DEFAULT_TTL_SECONDS, $ttl);
    }

    public function test_forgetting_a_version_deletes_only_that_versions_key(): void
    {
        $first  = $this->secret($this->tenant, 'cred-1', 'v1');
        $second = $this->secret($this->tenant, 'cred-1', 'v2');
        $this->cache()->put($first, 'tok-1', 3600);
        $this->cache()->put($second, 'tok-2', 3600);

        $this->cache()->forgetVersion($first);

        $this->assertNull($this->cache()->get($first));
        $this->assertSame('tok-2', $this->cache()->get($second));
    }

    public function test_forgetting_a_credential_deletes_every_version_of_it_and_no_other_credential(): void
    {
        $v1    = $this->secret($this->tenant, 'cred-1', 'v1');
        $v2    = $this->secret($this->tenant, 'cred-1', 'v2');
        $other = $this->secret($this->tenant, 'cred-10', 'v1');
        $this->cache()->put($v1, 'tok-1', 3600);
        $this->cache()->put($v2, 'tok-2', 3600);
        $this->cache()->put($other, 'tok-other', 3600);

        $this->cache()->forget($this->tenant, 'cred-1');

        $this->assertNull($this->cache()->get($v1));
        $this->assertNull($this->cache()->get($v2));
        $this->assertSame('tok-other', $this->cache()->get($other));
    }

    public function test_forgetting_a_tenant_deletes_all_of_its_tokens_and_no_other_tenants(): void
    {
        $otherTenant = 'other-' . $this->tenant;
        $first       = $this->secret($this->tenant, 'cred-1', 'v1');
        $second      = $this->secret($this->tenant, 'cred-2', 'v1');
        $foreign     = $this->secret($otherTenant, 'cred-1', 'v1');
        $this->cache()->put($first, 'tok-1', 3600);
        $this->cache()->put($second, 'tok-2', 3600);
        $this->cache()->put($foreign, 'tok-foreign', 3600);

        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('getId')->andReturn($this->tenant);

        $forgetters = iterator_to_array($this->app->tagged('tenancy.cache_forgetters'));
        $this->assertNotEmpty(array_filter($forgetters, static fn (object $f): bool => $f instanceof CredentialCacheForgetter));

        $this->app->make(CredentialCacheForgetter::class)->forget($tenant);

        $this->assertNull($this->cache()->get($first));
        $this->assertNull($this->cache()->get($second));
        $this->assertSame('tok-foreign', $this->cache()->get($foreign));
    }

    private function cache(): OAuth2TokenCache
    {
        return $this->app->make(OAuth2TokenCache::class);
    }

    private function secret(string $tenantId, string $id, string $version): CredentialSecret
    {
        return new CredentialSecret($tenantId, $id, 'oauth2_client_credentials', $version, [], ['api.example.com'], []);
    }

    private function key(string $tenantId, string $id, string $version): string
    {
        return OAuth2TokenCache::tenantKeyPrefix($tenantId) . "{$id}:{$version}";
    }

    /**
     * Deletes the keys the test may have left for one of its own tenants; never a flush.
     */
    private function removeKeysOf(string $tenantId): void
    {
        foreach (['cred-1', 'cred-2', 'cred-10'] as $id) {
            foreach (['v1', 'v2'] as $version) {
                Redis::connection()->del($this->key($tenantId, $id, $version));
            }
        }
    }
}
