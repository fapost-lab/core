<?php

declare(strict_types=1);

namespace Tests;

use App\Domains\Flow\Call\Egress\HostResolverInterface;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\SolutionCache;
use Fapost\Testing\Concerns\IsolatesLandlordPerParallelProcess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\CorePackageManifest;
use Tests\Support\FakeHostResolver;
use Tests\Support\OverlayPackages;

abstract class TestCase extends BaseTestCase
{
    use IsolatesLandlordPerParallelProcess;

    /**
     * Stands in for DNS in every test; see {@see FakeHostResolver}.
     */
    protected FakeHostResolver $hostResolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are rendered without a Vite manifest: tests assert on
        // responses, not on compiled assets, and CI has no public/build.
        $this->withoutVite();

        $this->hostResolver = new FakeHostResolver();
        $this->app->instance(HostResolverInterface::class, $this->hostResolver);
    }

    /**
     * The application as Core alone has it, whatever a local `composer overlay` installed into vendor/.
     *
     * Overlay packages ({@see OverlayPackages}) reach an application two ways, and both are closed before it boots:
     * Laravel's package discovery and Core's discovery of Solutions and connectors. The Solution cache is moved off
     * `bootstrap/cache/fapost-solutions.php`, which a developer's `solutions:cache` writes with the overlay's Solutions
     * and which would stay fresh for the same vendor/. Without an overlay, as in CI, nothing is left out.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath() . '/bootstrap/app.php';

        $overlay = OverlayPackages::in($app->basePath(), $app->basePath('vendor'));

        $app->instance(PackageManifest::class, new CorePackageManifest(new Filesystem(), $app->basePath(), $app->getCachedPackagesPath(), $overlay));
        $app->singleton(InstalledPackagesReader::class, static fn (Application $app): InstalledPackagesReader => new InstalledPackagesReader($app->basePath('vendor'), $overlay));
        $app->singleton(SolutionCache::class, static fn (Application $app): SolutionCache => new SolutionCache($app->bootstrapPath('cache/fapost-solutions.testing.php')));

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Landlord database this test uses under `--parallel`.
     *
     * A plain database test (`DatabaseTransactions`) gets the landlord tables from
     * the `migrate` Laravel runs when it creates the process's default database,
     * which records them in that database. Pointing landlord at the same database
     * keeps tables and record together, and Laravel recreates both at once.
     * {@see Feature\FeatureTestCase} wipes and migrates the landlord itself and
     * uses a database of its own.
     */
    protected function parallelLandlordDatabase(string $landlordDatabase, string $defaultDatabase, string $token): string
    {
        return "{$defaultDatabase}_test_{$token}";
    }
}
