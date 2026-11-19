<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use RuntimeException;

/**
 * A Redis connection for tests that need none: keeps string values with their TTL in an array and answers `get`,
 * `set ... EX`, `scan` and `unlink`. {@see self::$failing} makes every call throw, as an unreachable server does.
 *
 * It is both the factory and the connection it hands out.
 */
final class InMemoryRedis implements RedisFactory
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public bool $failing = false;

    public function connection($name = null): self
    {
        $this->guard();

        return $this;
    }

    public function get(string $key): string|false
    {
        $this->guard();

        return $this->values[$key] ?? false;
    }

    public function set(string $key, string $value, string $mode = '', int $ttl = 0): bool
    {
        $this->guard();

        $this->values[$key] = $value;
        $this->ttls[$key]   = $ttl;

        return true;
    }

    /**
     * @param  array{match?: string, count?: int}  $options
     *
     * @return array{0: int, 1: list<string>}
     */
    public function scan(mixed $cursor, array $options = []): array
    {
        $this->guard();

        $pattern = $options['match'] ?? '*';

        return [0, array_values(array_filter(array_keys($this->values), static fn (string $key): bool => fnmatch($pattern, $key)))];
    }

    /**
     * @param  list<string>  $parameters
     */
    public function command(string $method, array $parameters = []): int
    {
        $this->guard();

        if ('unlink' !== $method) {
            throw new RuntimeException("Unsupported command {$method}.");
        }

        foreach ($parameters as $key) {
            unset($this->values[$key], $this->ttls[$key]);
        }

        return count($parameters);
    }

    private function guard(): void
    {
        if ($this->failing) {
            throw new RuntimeException('redis down');
        }
    }
}
