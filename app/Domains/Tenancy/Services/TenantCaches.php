<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantCacheForgetterInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Throwable;

/**
 * Asks every {@see TenantCacheForgetterInterface} (Tenancy's own and those the other domains tag
 * `tenancy.cache_forgetters`) to forget a tenant. One that fails does not stop the others; the first failure is
 * rethrown afterwards, so the removal does not go on with entries left behind.
 */
final readonly class TenantCaches
{
    /**
     * @param  iterable<TenantCacheForgetterInterface>  $forgetters
     */
    public function __construct(private iterable $forgetters)
    {
    }

    public function forget(TenantInterface $tenant): void
    {
        $failure = null;

        foreach ($this->forgetters as $forgetter) {
            try {
                $forgetter->forget($tenant);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if (null !== $failure) {
            throw $failure;
        }
    }
}
