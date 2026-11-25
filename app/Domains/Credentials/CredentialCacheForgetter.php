<?php

declare(strict_types=1);

namespace App\Domains\Credentials;

use App\Domains\Shared\Infrastructure\RedisKeyPurger;
use App\Domains\Tenancy\Contracts\TenantCacheForgetterInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;

/**
 * Forgets the cached OAuth2 access tokens of a tenant (`credentials:oauth2:{tenant}:*`) when the tenant is removed
 * completely. Safe to repeat.
 */
final readonly class CredentialCacheForgetter implements TenantCacheForgetterInterface
{
    public function __construct(private RedisKeyPurger $purger)
    {
    }

    public function forget(TenantInterface $tenant): void
    {
        $this->purger->purge(OAuth2TokenCache::tenantKeyPrefix($tenant->getId()) . '*');
    }
}
