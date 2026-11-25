<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Credentials;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\Models\CredentialOAuthGrant;
use App\Domains\Credentials\OAuth2\OAuth2GrantStatus;
use App\Domains\Credentials\OAuth2TokenCache;
use App\Domains\Credentials\Types\OAuth2AuthorizationCodeType;
use App\Domains\Shared\Infrastructure\RedisKeyPurger;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Str;
use Psr\Log\NullLogger;
use Tests\Feature\FeatureTestCase;
use Tests\Support\InMemoryRedis;

/**
 * The current tenant and an in-memory Redis behind the OAuth2 token cache.
 */
abstract class CredentialsTestCase extends FeatureTestCase
{
    protected const string SECRET = 'super-secret-value-1';

    protected InMemoryRedis $redis;

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.redis.options.prefix' => '']);

        $tenant         = Tenant::query()->firstOrFail();
        $this->tenantId = $tenant->getId();
        $this->app->make(TenantContextInterface::class)->set($tenant);

        $this->redis = new InMemoryRedis();
        $this->app->instance(OAuth2TokenCache::class, new OAuth2TokenCache(
            $this->redis,
            $this->app->make(Encrypter::class),
            new RedisKeyPurger($this->redis, $this->app->make('config')),
            new NullLogger(),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function credential(array $attributes = [], ?string $tenantId = null): Credential
    {
        return Credential::query()->create([
            'tenant_id'         => $tenantId ?? $this->tenantId,
            'name'              => 'Credential ' . Str::random(6),
            'type'              => 'bearer',
            'settings'          => [],
            'allowed_hosts'     => ['api.example.com'],
            'secret'            => ['token' => self::SECRET],
            'secret_changed_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * An `oauth2_authorization_code` credential with a client on auth.example.com.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function oauthCredential(array $attributes = []): Credential
    {
        return $this->credential([
            'type'          => OAuth2AuthorizationCodeType::ID,
            'allowed_hosts' => ['api.example.com', 'auth.example.com'],
            'settings'      => [
                'authorize_url' => 'https://login.example.net/authorize',
                'token_url'     => 'https://auth.example.com/oauth/token',
                'client_id'     => 'client-1',
                'scope'         => 'read write',
                'client_auth'   => 'basic',
            ],
            'secret' => ['client_secret' => self::SECRET],
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function grant(Credential $credential, array $attributes = []): CredentialOAuthGrant
    {
        return CredentialOAuthGrant::query()->create([
            'credential_id'     => $credential->id,
            'tenant_id'         => $credential->tenant_id,
            'status'            => OAuth2GrantStatus::Connected,
            'access_token'      => 'access-token-1',
            'refresh_token'     => 'refresh-token-1',
            'access_expires_at' => now()->addHour(),
            'connected_at'      => now()->subDay(),
            'refreshed_at'      => now()->subDay(),
            ...$attributes,
        ]);
    }
}
