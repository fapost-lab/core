<?php

declare(strict_types=1);

namespace Fapost\Testing\Support;

use BadMethodCallException;
use Illuminate\Contracts\Redis\Factory;

/**
 * The few Redis commands Core's webhook path and message sender use, kept in memory.
 *
 * Both are the registry lookup and write-through (`get`, `set`, `del`), the ingress deduplication and the outbound
 * idempotency (`set` with `NX`) and the per-recipient rate limit (`incr` and `expire` in a pipeline). Entries never
 * expire during a test. A command it does not know fails loudly instead of answering something: a connector test must
 * never reach a real Redis, nor silently pass over a command it was not written for.
 *
 * It is the factory and its own connection, so the `Redis` facade, `Illuminate\Contracts\Redis\Factory` and
 * `->connection()` all land here.
 *
 * @internal part of the connector testing kit, not its API
 */
final class InMemoryRedis implements Factory
{
    /** @var array<string, string> */
    private array $values = [];

    /**
     * @param  list<mixed>  $arguments
     */
    public function __call(string $command, array $arguments): never
    {
        throw new BadMethodCallException("The connector testing kit's in-memory Redis does not implement `{$command}`.");
    }

    public function connection($name = null): self
    {
        return $this;
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /**
     * Understands the `EX seconds` and `NX` options, in any order after the value.
     */
    public function set(string $key, mixed $value, mixed ...$options): bool
    {
        $options = array_map(static fn (mixed $option): string => mb_strtoupper((string) $option), $options);

        if (in_array('NX', $options, true) && array_key_exists($key, $this->values)) {
            return false;
        }

        $this->values[$key] = (string) $value;

        return true;
    }

    public function del(string ...$keys): int
    {
        $removed = 0;

        foreach ($keys as $key) {
            if (array_key_exists($key, $this->values)) {
                unset($this->values[$key]);
                ++$removed;
            }
        }

        return $removed;
    }

    public function incr(string $key): int
    {
        $next = (int) ($this->values[$key] ?? 0) + 1;

        $this->values[$key] = (string) $next;

        return $next;
    }

    public function expire(string $key, int $seconds): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @param  (callable(self): mixed)|null  $callback
     *
     * @return list<mixed>
     */
    public function pipeline(?callable $callback = null): array
    {
        $results = [];

        if (null !== $callback) {
            $callback(new class ($this, $results) {
                /**
                 * @param  list<mixed>  $results
                 */
                public function __construct(private readonly InMemoryRedis $redis, private array &$results)
                {
                }

                /**
                 * @param  list<mixed>  $arguments
                 */
                public function __call(string $command, array $arguments): self
                {
                    $this->results[] = $this->redis->{$command}(...$arguments);

                    return $this;
                }
            });
        }

        return $results;
    }
}
