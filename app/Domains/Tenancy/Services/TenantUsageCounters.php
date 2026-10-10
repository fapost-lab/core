<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Closure;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Enums\LimitKind;
use LogicException;

/**
 * The counters behind {@see \Fapost\Foundation\Quota\Contracts\TenantUsageInterface}: one closure
 * per limit key, registered by the domain that owns the thing counted, while the application boots.
 *
 * A counter runs inside the tenant's switch and answers from the tenant's own storage. The registry
 * keeps the closures only, never a tenant's data, so a worker may hold it for its whole life.
 */
final class TenantUsageCounters
{
    /**
     * @var array<string, Closure(): int>
     */
    private array $counters = [];

    public function __construct(private readonly LimitRegistryInterface $limits)
    {
    }

    /**
     * @param  Closure(): int  $counter  counts for the tenant that is current when it runs
     *
     * @throws LogicException when the key is not a registered Records or Bytes key, or has a counter already
     */
    public function register(string $key, Closure $counter): void
    {
        $definition = $this->limits->find($key);

        if (null === $definition) {
            throw new LogicException(sprintf('Limit "%s" is not registered.', $key));
        }

        if (LimitKind::PerPeriod === $definition->kind) {
            throw new LogicException(sprintf('Limit "%s" is a per-period limit; the operator counts it.', $key));
        }

        if (isset($this->counters[$key])) {
            throw new LogicException(sprintf('Limit "%s" already has a usage counter.', $key));
        }

        $this->counters[$key] = $counter;
    }

    /**
     * @return Closure(): int|null
     */
    public function find(string $key): ?Closure
    {
        return $this->counters[$key] ?? null;
    }
}
