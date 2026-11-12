<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Solution\DTO\SolutionUiBundle;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Solution\Services\SolutionUiCompatibility;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Which Solutions the console serves to the current tenant: active for the tenant ({@see ActiveSolutions}) and, when
 * the Solution ships a console UI, with that UI published and built for the kit, Vue and Inertia this Core ships
 * ({@see SolutionUiCompatibility}). Everything a Solution adds to the console — its routes, its application, its
 * declarations, its page map — is shown only for these.
 *
 * An active Solution whose published UI no longer fits (a Core upgrade moved the kit past its range) keeps its
 * activation, data, flows and PHP, but is not served: {@see self::needsUpdate()} says so, and the app switcher and its
 * pages show that it needs an update (`adr-20261014-solution-console-ui`, Stability).
 *
 * Bound `scoped`, memoized per request. Without a tenant nothing is served; when no installed Solution has a published
 * bundle, the bundle map and the Inertia version suffix are answered without asking the database.
 */
final class SolutionUi implements SolutionUiMapInterface
{
    /** @var list<string>|null */
    private ?array $served = null;

    /** @var list<string>|null */
    private ?array $outdated = null;

    public function __construct(
        private readonly PublishedSolutionUi $published,
        private readonly InstalledSolutions $installed,
        private readonly SolutionUiCompatibility $compatibility,
        private readonly TenantContextInterface $tenantContext,
        private readonly Container $container,
    ) {
    }

    /**
     * @return list<string> sorted
     */
    public function servedIds(): array
    {
        if (null === $this->served) {
            $this->classify();
        }

        return $this->served ?? [];
    }

    public function isServed(string $solutionId): bool
    {
        return in_array($solutionId, $this->servedIds(), true);
    }

    /**
     * Whether the Solution is active for the tenant but its published console UI was built for a kit, Vue or Inertia
     * this Core does not ship: it is not served, and the console says it needs an update.
     */
    public function needsUpdate(string $solutionId): bool
    {
        if (null === $this->outdated) {
            $this->classify();
        }

        return in_array($solutionId, $this->outdated ?? [], true);
    }

    /**
     * Whether the Solution's console UI may be served once it is active: it is installed and either ships no console UI,
     * or its UI is published and fits the kit, Vue and Inertia this Core ships.
     */
    public function isServable(string $solutionId): bool
    {
        $solution = $this->installed->find($solutionId);

        if (null === $solution) {
            return false;
        }

        if (null === $solution->ui) {
            return true;
        }

        $bundle = $this->published->find($solutionId);

        return null !== $bundle && [] === $this->compatibility->mismatches($bundle);
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

    /**
     * Splits the Solutions active for the tenant into those served and those whose published UI no longer fits.
     */
    private function classify(): void
    {
        $this->served   = [];
        $this->outdated = [];

        if (! $this->tenantContext->isResolved() || [] === $this->installed->all()) {
            return;
        }

        foreach ($this->container->make(ActiveSolutions::class)->ids() as $id) {
            $solution = $this->installed->find($id);

            if (null !== $solution && null === $solution->ui) {
                $this->served[] = $id;

                continue;
            }

            $bundle = $this->published->find($id);

            if (null === $bundle) {
                continue;
            }

            if ([] !== $this->compatibility->mismatches($bundle)) {
                $this->outdated[] = $id;
            } elseif (null !== $solution) {
                $this->served[] = $id;
            }
        }
    }
}
