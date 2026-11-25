<?php

declare(strict_types=1);

namespace App\Domains\Shared\Infrastructure;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;

use function mb_strlen;

/**
 * Deletes the Redis keys that match a pattern: SCAN, then UNLINK in batches, so a large keyspace blocks Redis for no
 * longer than a batch. The tenant cache forgetters of Flow and Credentials and the OAuth2 token cache share it.
 *
 * Holds the config repository (the connection's key prefix), so it is bound scoped
 * ({@see \App\Providers\AppServiceProvider}).
 */
final readonly class RedisKeyPurger
{
    private const int BATCH = 500;

    public function __construct(private RedisFactory $redis, private ConfigRepository $config)
    {
    }

    /**
     * @param  string  $pattern  a glob over the keys without the connection prefix
     */
    public function purge(string $pattern): void
    {
        $connection = $this->redis->connection();
        $prefix     = (string) $this->config->get('database.redis.options.prefix', '');
        $cursor     = null;

        // phpredis starts an iteration from null (not 0), matches the pattern against the prefixed key, and the
        // connection answers false when the iteration ended without a hit.
        do {
            $scan = $connection->scan($cursor, ['match' => $prefix . $pattern, 'count' => self::BATCH]);

            if (!is_array($scan)) {
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
