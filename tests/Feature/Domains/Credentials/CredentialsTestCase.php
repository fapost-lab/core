<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Credentials;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\OAuth2TokenCache;
use App\Domains\Credentials\RedisKeyPurger;
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
}
