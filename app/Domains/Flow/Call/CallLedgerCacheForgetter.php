<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use App\Domains\Tenancy\Contracts\TenantCacheForgetterInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

use function mb_strlen;

/**
 * Forgets the `call` ledger records of a tenant (`flow:call:{tenant}:*`, response bodies kept for up to the dedup
 * window) when the tenant is removed completely.
 *
 * Walks the keyspace with SCAN and deletes with UNLINK, so a large keyspace blocks Redis for no longer than a
 * batch. Safe to repeat.
 */
final readonly class CallLedgerCacheForgetter implements TenantCacheForgetterInterface
{
    private const int BATCH = 500;

    public function __construct(private RedisFactory $redis)
    {
    }

    public function forget(TenantInterface $tenant): void
    {
        $connection = $this->redis->connection();
        $prefix     = (string) config('database.redis.options.prefix', '');
        $pattern    = 'flow:call:' . $tenant->getId() . ':*';
        $cursor     = null;

        // phpredis starts an iteration from null (not 0), matches the pattern against the prefixed key, and the
        // connection answers false when the iteration ended without a hit.
        do {
            $scan = $connection->scan($cursor, ['match' => $prefix . $pattern, 'count' => self::BATCH]);

            if (! is_array($scan)) {
                return;
            }

            [$cursor, $keys] = $scan;

            // The client adds its prefix again on UNLINK; the keys SCAN returns carry it already.
            $keys = array_map(
                static fn (string $key): string => '' !== $prefix && str_starts_with($key, $prefix) ? mb_substr($key, mb_strlen($prefix, '8bit'), null, '8bit') : $key,
                is_array($keys) ? $keys : [],
            );

            if ([] !== $keys) {
                $connection->command('unlink', $keys);
            }
        } while (0 !== (int) $cursor);
    }
}
