<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionEntitlements;
use App\Domains\Solution\Services\SolutionManifestValidator;
use App\Domains\Solution\Services\SolutionUiPublisher;
use App\Http\Shell\SolutionExtensions;
use App\Http\Shell\SolutionRoutes;
use App\Http\Shell\SolutionUi;
use App\Http\Shell\SolutionUiMapInterface;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;

/**
 * Installs the fixture Solution `showcase` (tests/Fixtures/Solutions/Showcase) as `platform:update` and a booting
 * application would: a throwaway vendor tree linking the package, its provider booted through Core's provider (so its
 * console declarations are bound to its id), its routes registered and, unless told otherwise, its `dist/` published
 * into a throwaway web root. Call {@see removeShowcase()} in tearDown.
 */
trait InstallsShowcaseSolution
{
    private ?FakeVendor $showcaseVendor = null;

    private ?string $showcasePublic = null;

    protected static function showcasePackage(): string
    {
        return dirname(__DIR__) . '/Fixtures/Solutions/Showcase/package';
    }

    /**
     * @param  string|null  $package  a copy of the fixture package to install instead, as a test changed it
     * @param  list<array<string, mixed>>  $alsoInstalled  composer.json of further packages to install beside it
     */
    protected function installShowcase(bool $publish = true, ?string $package = null, array $alsoInstalled = []): void
    {
        $this->removeShowcase();

        $this->showcaseVendor = new FakeVendor();
        $this->showcaseVendor->linkedPackage(
            json_decode((string) file_get_contents(($package ?? self::showcasePackage()) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR),
            $package ?? self::showcasePackage(),
            '1.0.0',
        );

        foreach ($alsoInstalled as $composer) {
            $this->showcaseVendor->package($composer);
        }

        $this->showcaseVendor->write();

        $this->showcasePublic = dirname($this->showcaseVendor->path) . '/public';
        mkdir($this->showcasePublic, 0o777, true);
        $this->app->usePublicPath($this->showcasePublic);

        $cache      = new SolutionCache(dirname($this->showcaseVendor->path) . '/fapost-solutions.php');
        $discoverer = new SolutionDiscoverer(
            new InstalledPackagesReader($this->showcaseVendor->path),
            new SolutionManifestValidator(new ManifestParser()),
        );

        $this->app->instance(SolutionCache::class, $cache);
        $this->app->instance(SolutionDiscoverer::class, $discoverer);
        $this->app->instance(InstalledSolutions::class, new InstalledSolutions($cache, $discoverer));
        $this->forgetSolutionState();

        (new SolutionServiceProvider($this->app))->register();
        $this->app->make(SolutionRoutes::class)->register();
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();

        if ($publish) {
            $this->publishShowcase();
        }
    }

    protected function publishShowcase(): void
    {
        $publisher = $this->app->make(SolutionUiPublisher::class);
        $check     = $publisher->check($this->app->make(InstalledSolutions::class)->all());

        $this->assertSame([], $check['problems']);
        $publisher->publish($check['sources']);
        $this->forgetSolutionState();
    }

    protected function activateShowcase(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'showcase', '--tenant' => 'main'])->assertExitCode(0);
        $this->forgetSolutionState();
    }

    protected function deactivateShowcase(): void
    {
        $this->artisan('solutions:deactivate', ['solution' => 'showcase', '--tenant' => 'main'])->assertExitCode(0);
        $this->forgetSolutionState();
    }

    protected function removeShowcase(): void
    {
        if (null !== $this->showcaseVendor) {
            (new Filesystem())->deleteDirectory(dirname($this->showcaseVendor->path));
        }

        $this->showcaseVendor = null;
        $this->showcasePublic = null;
    }

    /**
     * What a request or the process remembered about Solutions; a new request would start without it.
     */
    private function forgetSolutionState(): void
    {
        foreach ([ActiveSolutions::class, SolutionEntitlements::class, PublishedSolutionUi::class, SolutionUi::class, SolutionUiMapInterface::class, SolutionExtensions::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }
}
