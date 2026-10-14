<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\InstalledSolution;

/**
 * The Solutions installed in this image whose manifest is valid. Read-only.
 *
 * Reads the compiled cache when it is fresh and otherwise builds the list from the package files,
 * without writing anything. The list depends on the image alone, never on a tenant, so keeping it for
 * the life of the process is safe; workers restart after an upgrade anyway.
 */
final class InstalledSolutions
{
    /** @var array<string, InstalledSolution>|null */
    private ?array $byId = null;

    public function __construct(
        private readonly SolutionCache $cache,
        private readonly SolutionDiscoverer $discoverer,
    ) {
    }

    /**
     * @return list<InstalledSolution> ordered by id
     */
    public function all(): array
    {
        return array_values($this->load());
    }

    public function find(string $id): ?InstalledSolution
    {
        return $this->load()[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->load()[$id]);
    }

    /**
     * @return array<string, InstalledSolution>
     */
    private function load(): array
    {
        if (null === $this->byId) {
            $solutions = $this->cache->read() ?? $this->discoverer->discover()->valid;

            $this->byId = [];

            foreach ($solutions as $solution) {
                $this->byId[$solution->id] = $solution;
            }
        }

        return $this->byId;
    }
}
