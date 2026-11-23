<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * One writer at a time for a credential's grant. Many providers rotate the refresh token on every use: two workers
 * refreshing at once leave one of them with a token the provider no longer accepts, and the credential dead. Whoever
 * holds the lock reads the grant again before asking the provider.
 *
 * Keyed `credentials:oauth2-refresh:{tenant}:{credential}` in the default cache store; held at most
 * {@see self::TTL_SECONDS} seconds, waited for at most `$waitSeconds`.
 */
final readonly class OAuth2GrantLock
{
    public const int TTL_SECONDS = 30;

    public function __construct(
        private Repository $cache,
        private int $waitSeconds = 5,
    ) {
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     *
     * @return T
     *
     * @throws OAuth2GrantLockBusyException when the lock is not had in time, the store has no locks, or the store
     *                                       failed while taking it (Redis down): no write of the grant without the lock
     */
    public function run(string $tenantId, string $credentialId, callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if (!$store instanceof LockProvider) {
            throw new OAuth2GrantLockBusyException('The cache store offers no locks.');
        }

        try {
            $lock = $store->lock("credentials:oauth2-refresh:{$tenantId}:{$credentialId}", self::TTL_SECONDS);

            $lock->block($this->waitSeconds);
        } catch (LockTimeoutException) {
            throw new OAuth2GrantLockBusyException('The grant of the credential is being refreshed elsewhere.');
        } catch (Throwable) {
            // Only the taking of the lock is caught here, never the callback. The store's own message (a host, a
            // connection error) is not carried on.
            throw new OAuth2GrantLockBusyException('The lock of the grant could not be taken: the cache store failed.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
