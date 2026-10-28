<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantCacheForgetterInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Illuminate\Contracts\Cache\Factory;
use Spatie\LaravelSettings\Support\SettingsCacheFactory;

/**
 * Forgets what Tenancy itself keeps in the cache for a tenant: its settings (no TTL, under `tenant.<id>.settings.*`) and
 * its permissions (a key of the tenant's own, see {@see TenantSwitcher}).
 *
 * The settings key follows the tenant context, so this runs inside the tenant's switch (the contract says it does).
 */
final readonly class TenantOwnCacheForgetter implements TenantCacheForgetterInterface
{
    public function __construct(
        private SettingsCacheFactory $settings,
        private Factory $cache,
        private string $permissionCacheKey,
        private string $permissionCacheStore,
    ) {
    }

    public function forget(TenantInterface $tenant): void
    {
        foreach ($this->settings->all() as $cache) {
            $cache->clear();
        }

        $store = 'default' === $this->permissionCacheStore ? null : $this->permissionCacheStore;

        $this->cache->store($store)->forget($this->permissionCacheKey . '.tenant.' . $tenant->getId());
    }
}
