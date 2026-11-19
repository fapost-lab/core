<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Credentials;

use App\Domains\Credentials\CredentialCacheForgetter;
use App\Domains\Credentials\CredentialTypeRegistry;
use App\Domains\Credentials\OAuth2TokenCache;
use App\Domains\Credentials\RedisKeyPurger;
use App\Domains\Credentials\Types\ApiKeyHeaderCredentialType;
use App\Domains\Credentials\Types\BearerCredentialType;
use App\Domains\Credentials\Types\OAuth2ClientCredentialsType;
use App\Domains\Tenancy\Contracts\TenantCacheForgetterInterface;
use App\Domains\Tenancy\Models\Tenant;
use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use InvalidArgumentException;
use stdClass;
use Tests\Support\InMemoryRedis;
use Tests\TestCase;

final class CredentialTypeRegistryTest extends TestCase
{
    public function test_the_built_in_types_are_registered_in_order(): void
    {
        $registry = $this->app->make(CredentialTypeRegistry::class);

        $this->assertSame(
            ['bearer', 'basic', 'api_key_header', 'api_key_query', 'oauth2_client_credentials'],
            $registry->ids(),
        );
        $this->assertSame($registry->ids(), array_map(static fn (HttpCredentialTypeInterface $type): string => $type->id(), $registry->all()));
        $this->assertTrue($registry->has('bearer'));
        $this->assertFalse($registry->has('nope'));
        $this->assertInstanceOf(OAuth2ClientCredentialsType::class, $registry->get('oauth2_client_credentials'));
    }

    public function test_it_is_a_singleton_that_builds_a_type_on_every_call(): void
    {
        $registry = $this->app->make(CredentialTypeRegistry::class);

        $this->assertSame($registry, $this->app->make(CredentialTypeRegistry::class));
        $this->assertNotSame($registry->get('bearer'), $registry->get('bearer'));
    }

    public function test_an_unknown_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(CredentialTypeRegistry::class)->get('nope');
    }

    public function test_a_class_that_is_not_a_credential_type_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(CredentialTypeRegistry::class)->register(stdClass::class);
    }

    public function test_an_id_cannot_be_taken_twice_by_different_classes(): void
    {
        $registry = new CredentialTypeRegistry($this->app);
        $registry->register(BearerCredentialType::class);
        $registry->register(BearerCredentialType::class);

        $impostor = new class () implements HttpCredentialTypeInterface {
            public function id(): string
            {
                return 'bearer';
            }

            public function label(): string
            {
                return 'Impostor';
            }

            public function fields(): array
            {
                return [];
            }

            public function authorize(\Fapost\Foundation\Credentials\DTO\CredentialSecret $secret): \Fapost\Foundation\Credentials\DTO\HttpAuthorization
            {
                return new \Fapost\Foundation\Credentials\DTO\HttpAuthorization();
            }
        };

        $this->assertSame(['bearer'], $registry->ids());

        $this->expectException(InvalidArgumentException::class);

        $registry->register($impostor::class);
    }

    public function test_every_type_declares_valid_fields_and_a_secret(): void
    {
        foreach ($this->app->make(CredentialTypeRegistry::class)->all() as $type) {
            $keys = array_map(static fn ($field): string => $field->key, $type->fields());

            $this->assertSame($keys, array_values(array_unique($keys)), $type->id());
            $this->assertNotEmpty(array_filter($type->fields(), static fn ($field): bool => $field->secret), $type->id());
        }

        $this->assertInstanceOf(ApiKeyHeaderCredentialType::class, $this->app->make(CredentialTypeRegistry::class)->get('api_key_header'));
    }

    public function test_the_forgetter_is_tagged_and_removes_only_the_tenants_tokens(): void
    {
        config(['database.redis.options.prefix' => '']);
        $redis         = new InMemoryRedis();
        $redis->values = [
            'credentials:oauth2:tenant-1:c1:v1' => 'a',
            'credentials:oauth2:tenant-1:c2:v3' => 'b',
            'credentials:oauth2:tenant-2:c1:v1' => 'c',
            'flow:call:tenant-1:k:done'         => 'd',
        ];
        $purger = new RedisKeyPurger($redis, $this->app->make('config'));
        $tenant = (new Tenant())->forceFill(['id' => 'tenant-1']);

        (new CredentialCacheForgetter($purger))->forget($tenant);

        $this->assertSame(['credentials:oauth2:tenant-2:c1:v1', 'flow:call:tenant-1:k:done'], array_keys($redis->values));
        $this->assertInstanceOf(TenantCacheForgetterInterface::class, $this->app->make(CredentialCacheForgetter::class));
        $this->assertContains(
            CredentialCacheForgetter::class,
            array_map(static fn (object $forgetter): string => $forgetter::class, [...$this->app->tagged('tenancy.cache_forgetters')]),
        );
    }

    public function test_the_token_cache_is_rebuilt_for_every_scope(): void
    {
        $first = $this->app->make(OAuth2TokenCache::class);

        $this->assertSame($first, $this->app->make(OAuth2TokenCache::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(OAuth2TokenCache::class));
    }
}
