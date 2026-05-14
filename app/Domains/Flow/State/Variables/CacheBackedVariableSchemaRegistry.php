<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Variables;

use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\Models\VariableSchemaEntry;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Read-through Redis cache over the tenant_variable_schema table.
 *
 * Cache key: "tenant:{tenant_id}:variable_schema"
 * No TTL — write-through on publish via {@see invalidate()}.
 * Bound as scoped (per-request) so the loaded map stays hot within a request
 * without surviving across requests in long-lived workers.
 */
final class CacheBackedVariableSchemaRegistry implements VariableSchemaRegistryInterface
{
    /** @var array<string, VariableType>|null Lazily loaded map for the current request. */
    private ?array $map = null;

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly CacheRepository $cache,
    ) {
    }

    public function get(string $storage, ?string $group, string $name): ?VariableType
    {
        $key = $this->buildKey($storage, $group, $name);

        return $this->loadMap()[$key] ?? null;
    }

    /** @return array<string, VariableType> */
    public function getAllForTenant(): array
    {
        return $this->loadMap();
    }

    public function invalidate(): void
    {
        if (!$this->tenantContext->isResolved()) {
            $this->map = null;

            return;
        }

        $this->cache->forget($this->cacheKey());
        $this->map = null;
    }

    private function buildKey(string $storage, ?string $group, string $name): string
    {
        return "{$storage}:" . ($group ?? '') . ":{$name}";
    }

    /** @return array<string, VariableType> */
    private function loadMap(): array
    {
        if (null !== $this->map) {
            return $this->map;
        }

        /** @var array<string, string>|null $cached */
        $cached = $this->cache->get($this->cacheKey());

        if (null !== $cached) {
            $this->map = $this->hydrateMap($cached);

            return $this->map;
        }

        $raw = VariableSchemaEntry::query()
            ->where('tenant_id', $this->tenantContext->get()->getId())
            ->get(['storage', 'group', 'name', 'type']);

        $serialized = [];

        foreach ($raw as $entry) {
            /** @var VariableSchemaEntry $entry */
            $key              = $this->buildKey($entry->storage, $entry->group, $entry->name);
            $serialized[$key] = $entry->type->value;
        }

        // No TTL — write-through on publish.
        $this->cache->forever($this->cacheKey(), $serialized);

        $this->map = $this->hydrateMap($serialized);

        return $this->map;
    }

    private function cacheKey(): string
    {
        return 'tenant:' . $this->tenantContext->get()->getId() . ':variable_schema';
    }

    /**
     * @param  array<string, string>  $raw
     *
     * @return array<string, VariableType>
     */
    private function hydrateMap(array $raw): array
    {
        $map = [];

        foreach ($raw as $key => $typeValue) {
            $type = VariableType::tryFrom($typeValue);

            if (null !== $type) {
                $map[$key] = $type;
            }
        }

        return $map;
    }
}
