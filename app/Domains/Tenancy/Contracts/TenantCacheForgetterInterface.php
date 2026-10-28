<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

/**
 * Forgets cache entries of one tenant that never expire or outlive it, when the tenant is removed completely. A domain
 * that keeps such entries implements it and tags the binding `tenancy.cache_forgetters`.
 *
 * Called while the tenant's schema still exists and its context is current, so an implementation whose keys depend
 * on the context (settings) may use it. Safe to repeat.
 */
interface TenantCacheForgetterInterface
{
    public function forget(TenantInterface $tenant): void;
}
