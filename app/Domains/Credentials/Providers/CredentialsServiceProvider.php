<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Providers;

use App\Domains\Credentials\CredentialCacheForgetter;
use App\Domains\Credentials\CredentialTypeRegistry;
use App\Domains\Credentials\CredentialWriter;
use App\Domains\Credentials\EloquentCredentialStore;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\OAuth2TokenCache;
use App\Domains\Credentials\Policies\CredentialPolicy;
use App\Domains\Credentials\Types\ApiKeyHeaderCredentialType;
use App\Domains\Credentials\Types\ApiKeyQueryCredentialType;
use App\Domains\Credentials\Types\BasicCredentialType;
use App\Domains\Credentials\Types\BearerCredentialType;
use App\Domains\Credentials\Types\OAuth2ClientCredentialsType;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Credentials bounded context: the saved credentials of a tenant, the types that apply them to an HTTP request and
 * the OAuth2 token cache.
 *
 * The type registry is a singleton of classes. Everything that reads or writes the tenant's credentials, or holds
 * the config repository, is scoped, so no job inherits another's tenant.
 */
final class CredentialsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CredentialTypeRegistry::class, static function (Container $app): CredentialTypeRegistry {
            $registry = new CredentialTypeRegistry($app);

            foreach ([
                BearerCredentialType::class,
                BasicCredentialType::class,
                ApiKeyHeaderCredentialType::class,
                ApiKeyQueryCredentialType::class,
                OAuth2ClientCredentialsType::class,
            ] as $type) {
                $registry->register($type);
            }

            return $registry;
        });

        // Scoped: it reaches the config repository (the Redis key prefix) through the purger.
        $this->app->scoped(OAuth2TokenCache::class);
        $this->app->scoped(CredentialStoreInterface::class, EloquentCredentialStore::class);
        $this->app->scoped(CredentialWriter::class);
        $this->app->tag(CredentialCacheForgetter::class, 'tenancy.cache_forgetters');
    }

    public function boot(): void
    {
        Gate::policy(Credential::class, CredentialPolicy::class);
    }
}
