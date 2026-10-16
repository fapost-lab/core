<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\InstalledConnector;

/**
 * The connectors installed in this image whose manifest is valid. Read-only.
 *
 * Reads the compiled cache when it is fresh and otherwise builds the list from the package files, without writing
 * anything. Like {@see InstalledSolutions} it depends on the image alone, never on a tenant.
 */
final class InstalledConnectors
{
    /** @var array<string, InstalledConnector>|null */
    private ?array $byId = null;

    public function __construct(
        private readonly SolutionCache $cache,
        private readonly SolutionDiscoverer $discoverer,
    ) {
    }

    /**
     * @return list<InstalledConnector> ordered by id
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    public function find(string $id): ?InstalledConnector
    {
        return $this->load()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->load()[$id]);
    }

    /**
     * @return array<string, InstalledConnector>
     */
    private function load(): array
    {
        if (null === $this->byId) {
            $connectors = $this->cache->connectors() ?? $this->discoverer->discoverAll()->connectors->valid;

            $this->byId = [];

            foreach ($connectors as $connector) {
                $this->byId[$connector->id] = $connector;
            }
        }

        return $this->byId;
    }
}
