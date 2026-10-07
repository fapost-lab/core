<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use LogicException;

/**
 * In-memory registry of the limit keys the platform can enforce.
 *
 * A worker-lifetime singleton: Core and Solutions register keys while the application boots, then
 * {@see self::freeze()} closes it (outside testing), as the node handler registry does.
 */
final class LimitRegistry implements LimitRegistryInterface
{
    /**
     * @var array<string, LimitDefinition>
     */
    private array $limits = [];

    private bool $frozen = false;

    public function register(LimitDefinition $limit): void
    {
        if ($this->frozen) {
            throw new LogicException(sprintf('LimitRegistry is frozen; cannot register "%s".', $limit->key));
        }

        if (isset($this->limits[$limit->key])) {
            throw new LogicException(sprintf('Limit "%s" is already registered.', $limit->key));
        }

        $this->limits[$limit->key] = $limit;
    }

    public function find(string $key): ?LimitDefinition
    {
        return $this->limits[$key] ?? null;
    }

    /**
     * @return list<LimitDefinition>
     */
    public function all(): array
    {
        $limits = $this->limits;
        ksort($limits);

        return array_values($limits);
    }

    /**
     * Close the registry against further registrations.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }
}
