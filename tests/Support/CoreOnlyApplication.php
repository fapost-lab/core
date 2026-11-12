<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\SolutionCache;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;

/**
 * Closes the two ways overlay packages ({@see OverlayPackages}) reach an application, before it boots: Laravel's
 * package discovery and Core's discovery of Solutions and connectors.
 *
 * The Solution cache is moved off `bootstrap/cache/fapost-solutions.php`, which a developer's `solutions:cache` writes
 * with the overlay's Solutions and which would stay fresh for the same vendor/. Without an overlay, as in CI, nothing
 * is left out.
 */
final class CoreOnlyApplication
{
    /**
     * @return list<string> the overlay packages that were left out
     */
    public static function apply(Application $app): array
    {
        $overlay = OverlayPackages::in($app->basePath(), $app->basePath('vendor'));

        $app->instance(PackageManifest::class, new CorePackageManifest(new Filesystem(), $app->basePath(), $app->getCachedPackagesPath(), $overlay));
        $app->singleton(InstalledPackagesReader::class, static fn (Application $app): InstalledPackagesReader => new InstalledPackagesReader($app->basePath('vendor'), $overlay));
        $app->singleton(SolutionCache::class, static fn (Application $app): SolutionCache => new SolutionCache($app->bootstrapPath('cache/fapost-solutions.testing.php')));

        return $overlay;
    }
}
