<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionUiBundle;

/**
 * The console UI bundles of the installed Solutions that are published under `public/solutions/`, whatever the
 * tenant: which of them a tenant gets is the business of activation.
 *
 * Read from {@see SolutionCache} when it is fresh; otherwise worked out from the files: the Solution's `fapost-ui.json`
 * holds up and the directory of its hash exists. A Solution whose build changed since the last `platform:update` is
 * therefore not published until the next one. Depends on the image alone, so a process may keep it.
 */
final class PublishedSolutionUi
{
    /** @var array<string, SolutionUiBundle>|null */
    private ?array $bundles = null;

    public function __construct(
        private readonly SolutionCache $cache,
        private readonly InstalledSolutions $installed,
        private readonly SolutionUiBundleReader $reader,
        private readonly SolutionUiPublisher $publisher,
    ) {
    }

    /**
     * @return array<string, SolutionUiBundle> by Solution id, ordered by id
     */
    public function all(): array
    {
        if (null === $this->bundles) {
            $bundles = $this->cache->bundles() ?? $this->compute($this->installed->all())['bundles'];

            // Only Solutions that are still installed: the cache and the installed list go stale together, but a test or
            // a broken cache must not resurrect one.
            $bundles = array_filter($bundles, $this->installed->has(...), ARRAY_FILTER_USE_KEY);
            ksort($bundles);

            $this->bundles = $bundles;
        }

        return $this->bundles;
    }

    public function find(string $solutionId): ?SolutionUiBundle
    {
        return $this->all()[$solutionId] ?? null;
    }

    /**
     * The published bundles of the given Solutions read from the files, and the files a cache of them must watch.
     *
     * @param  list<InstalledSolution>  $solutions
     *
     * @return array{bundles: array<string, SolutionUiBundle>, watched: list<string>}
     */
    public function compute(array $solutions): array
    {
        $bundles = [];
        $watched = [];

        foreach ($solutions as $solution) {
            $source = $this->reader->read($solution);

            if (null !== $source?->manifestFile) {
                $watched[] = $source->manifestFile;
            }

            if (null !== $source?->bundle && $this->publisher->isPublished($source->bundle)) {
                $bundles[$solution->id] = $source->bundle;
            }
        }

        return ['bundles' => $bundles, 'watched' => $watched];
    }
}
