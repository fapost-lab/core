<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Solution\DTO\SolutionUiBundle;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Which Solutions the console serves to the current tenant: active for the tenant ({@see ActiveSolutions}) and, when
 * the Solution ships a console UI, with that UI published. Everything a Solution adds to the console — its routes, its
 * application, its declarations, its page map — is shown only for these.
 *
 * Bound `scoped`, memoized per request. Without a tenant nothing is served; when no installed Solution has a published
 * bundle, the bundle map and the Inertia version suffix are answered without asking the database.
 */
final class SolutionUi implements SolutionUiMapInterface
{
    /** @var list<string>|null */
    private ?array $served = null;

    public function __construct(
        private readonly PublishedSolutionUi $published,
        private readonly InstalledSolutions $installed,
        private readonly TenantContextInterface $tenantContext,
        private readonly Container $container,
    ) {
    }

    /**
     * @return list<string> sorted
     */
    public function servedIds(): array
    {
        if (null !== $this->served) {
            return $this->served;
        }

        if (! $this->tenantContext->isResolved() || [] === $this->installed->all()) {
            return $this->served = [];
        }

        $active = $this->container->make(ActiveSolutions::class)->ids();

        return $this->served = array_values(array_filter($active, $this->isServable(...)));
    }

    public function isServed(string $solutionId): bool
    {
        return in_array($solutionId, $this->servedIds(), true);
    }

    /**
     * The seam for the version check of `first-solution` phase 0: a Solution whose bundle was built against a kit,
     * Vue or Inertia this Core does not satisfy keeps its activation but gets no console UI. Today every one passes.
     */
    public function isServable(string $solutionId): bool
    {
        $solution = $this->installed->find($solutionId);

        return null !== $solution && (null === $solution->ui || null !== $this->published->find($solutionId));
    }

    public function bundles(): array
    {
        $bundles = [];

        foreach ($this->servedBundles() as $id => $bundle) {
            $url = fn (string $file): string => asset($bundle->directory() . '/' . $file);

            $bundles[$id] = [
                'pages'      => array_map($url, $bundle->pages),
                'components' => array_map($url, $bundle->components),
                'css'        => array_values(array_map($url, $bundle->css)),
            ];
        }

        return $bundles;
    }

    public function versionSuffix(): string
    {
        $bundles = $this->servedBundles();

        if ([] === $bundles) {
            return '';
        }

        return '|' . sha1(implode(',', array_map(
            static fn (SolutionUiBundle $bundle): string => $bundle->solutionId . ':' . $bundle->hash,
            array_values($bundles),
        )));
    }

    /**
     * @return array<string, SolutionUiBundle> by Solution id, ordered by id
     */
    private function servedBundles(): array
    {
        $published = $this->published->all();

        if ([] === $published) {
            return [];
        }

        return array_intersect_key($published, array_flip($this->servedIds()));
    }
}
