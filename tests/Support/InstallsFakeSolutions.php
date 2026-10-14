<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use Fapost\Foundation\Solution\Manifest\ManifestParser;

/**
 * Installs fixture Solutions of the given ids into a throwaway vendor tree and binds the catalog over it.
 * Call {@see removeFakeSolutions()} in tearDown.
 */
trait InstallsFakeSolutions
{
    private ?FakeVendor $fakeVendor = null;

    /**
     * Every id gets its own package; all share the fixture provider.
     */
    protected function installSolutions(string ...$ids): InstalledSolutions
    {
        $this->removeFakeSolutions();
        $this->fakeVendor = new FakeVendor();

        foreach ($ids as $id) {
            $this->fakeVendor->package(FakeVendor::solutionComposer(
                ['id' => $id, 'name' => ucfirst($id), 'actions' => [$id . '.run']],
                ['name' => 'acme/fapost-' . $id],
            ));
        }

        $this->fakeVendor->write();

        $installed = new InstalledSolutions(
            new SolutionCache(dirname($this->fakeVendor->path) . '/fapost-solutions.php'),
            new SolutionDiscoverer(
                new InstalledPackagesReader($this->fakeVendor->path),
                new SolutionManifestValidator(new ManifestParser()),
            ),
        );

        $this->app->instance(InstalledSolutions::class, $installed);
        $this->app->forgetInstance(ActiveSolutions::class);

        return $installed;
    }

    protected function removeFakeSolutions(): void
    {
        $this->fakeVendor?->cleanup();
        $this->fakeVendor = null;
    }
}
