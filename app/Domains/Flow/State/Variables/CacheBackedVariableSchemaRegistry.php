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
 *
 * Cache format (per key): JSON `{"type":"text","properties":{}}`. The older format
 * (plain type string) is silently skipped on hydration, causing a one-time DB reload.
 */
final class CacheBackedVariableSchemaRegistry implements VariableSchemaRegistryInterface
{
    /** @var array<string, VariableType>|null Lazily loaded type map for the current request. */
    private ?array $typeMap = null;

    /** @var array<string, array<string, mixed>>|null Lazily loaded properties map. */
    private ?array $propertiesMap = null;

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly CacheRepository $cache,
    ) {
    }

    public function get(string $storage, ?string $group, string $name): ?VariableType
    {
        $key = $this->buildKey($storage, $group, $name);

        return $this->loadTypesMap()[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function getProperties(string $storage, ?string $group, string $name): array
    {
        $key = $this->buildKey($storage, $group, $name);

        return $this->loadPropertiesMap()[$key] ?? [];
    }

    /** @return array<string, VariableType> */
    public function getAllForTenant(): array
    {
        return $this->loadTypesMap();
    }

    public function invalidate(): void
    {
        if (!$this->tenantContext->isResolved()) {
            $this->typeMap       = null;
            $this->propertiesMap = null;

            return;
        }

        $this->cache->forget($this->cacheKey());
        $this->typeMap       = null;
        $this->propertiesMap = null;
    }

    private function buildKey(string $storage, ?string $group, string $name): string
    {
        return "{$storage}:" . ($group ?? '') . ":{$name}";
    }

    /** @return array<string, VariableType> */
    private function loadTypesMap(): array
    {
        if (null !== $this->typeMap) {
            return $this->typeMap;
        }

        $this->loadAndHydrate();

        return $this->typeMap ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    private function loadPropertiesMap(): array
    {
        if (null !== $this->propertiesMap) {
            return $this->propertiesMap;
        }

        $this->loadAndHydrate();

        return $this->propertiesMap ?? [];
    }

    /**
     * Load from Redis cache (or DB on miss), then populate both in-memory maps.
     */
    private function loadAndHydrate(): void
    {
        /** @var array<string, string>|null $cached */
        $cached = $this->cache->get($this->cacheKey());

        if (null !== $cached) {
            [$this->typeMap, $this->propertiesMap] = $this->hydrateEntries($cached);

            return;
        }

        $raw = VariableSchemaEntry::query()
            ->where('tenant_id', $this->tenantContext->get()->getId())
            ->get(['storage', 'group', 'name', 'type', 'properties']);

        $serialized = [];

        foreach ($raw as $entry) {
            /** @var VariableSchemaEntry $entry */
            $key              = $this->buildKey($entry->storage, $entry->group, $entry->name);
            $serialized[$key] = json_encode([
                'type'       => $entry->type->value,
                'properties' => is_array($entry->properties) ? $entry->properties : [],
            ]);
        }

        // No TTL — write-through on publish.
        $this->cache->forever($this->cacheKey(), $serialized);

        [$this->typeMap, $this->propertiesMap] = $this->hydrateEntries($serialized);
    }

    private function cacheKey(): string
    {
        return 'tenant:' . $this->tenantContext->get()->getId() . ':variable_schema';
    }

    /**
     * Hydrate both type and properties maps from the serialized cache payload.
     * Entries that do not decode as `{type, properties}` JSON objects are silently
     * skipped — this handles the old plain-type-string format gracefully.
     *
     * @param  array<string, string>  $raw
     *
     * @return array{0: array<string, VariableType>, 1: array<string, array<string, mixed>>}
     */
    private function hydrateEntries(array $raw): array
    {
        $types      = [];
        $properties = [];

        foreach ($raw as $key => $payload) {
            /** @var mixed $decoded */
            $decoded = is_string($payload) ? json_decode($payload, true) : null;

            if (!is_array($decoded)) {
                // Legacy format: plain type string — upgrade on next publish.
                continue;
            }

            $type = VariableType::tryFrom((string)($decoded['type'] ?? ''));

            if (null !== $type) {
                $types[$key]      = $type;
                $properties[$key] = is_array($decoded['properties'] ?? null) ? $decoded['properties'] : [];
            }
        }

        return [$types, $properties];
    }
}
