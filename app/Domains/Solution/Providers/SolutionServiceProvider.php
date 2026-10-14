<?php

declare(strict_types=1);

namespace App\Domains\Solution\Providers;

use App\Domains\Solution\Console\ConsoleExtensionRegistry;
use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\Infrastructure\CoreInstalledSolutionCatalog;
use App\Domains\Solution\Infrastructure\EloquentSolutionActivationStore;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionUiBundleReader;
use App\Domains\Solution\Services\SolutionUiPublisher;
use Fapost\Foundation\Console\Contracts\ConsoleExtensionsInterface;
use Fapost\Foundation\Solution\Contracts\InstalledSolutionCatalogInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Solution domain and boots the service provider of every installed Solution with a valid
 * manifest. A Solution's provider is never listed in `extra.laravel.providers`: Laravel's package
 * discovery would boot it whether or not its manifest holds up.
 *
 * Around each Solution provider's `boot()` the console registry is opened for that Solution's id and closed again, so
 * every console declaration is bound to the Solution that made it; after the application booted the registry is frozen.
 */
final class SolutionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Depend on the image alone, never on a tenant, so a process may keep them.
        // singletonIf lets a test substitute them.
        $this->app->singletonIf(InstalledPackagesReader::class, fn ($app): InstalledPackagesReader => new InstalledPackagesReader($app->basePath('vendor')));
        $this->app->singletonIf(SolutionCache::class, fn ($app): SolutionCache => new SolutionCache($app->bootstrapPath('cache/fapost-solutions.php')));
        $this->app->singletonIf(InstalledSolutions::class);
        $this->app->singletonIf(ConsoleExtensionRegistry::class);
        $this->app->singletonIf(ConsoleExtensionsInterface::class, static fn (Application $app): ConsoleExtensionRegistry => $app->make(ConsoleExtensionRegistry::class));
        // The web root is read when the publisher is made, so a test can point it elsewhere first.
        $this->app->bindIf(SolutionUiPublisher::class, static fn (Application $app): SolutionUiPublisher => new SolutionUiPublisher(
            $app->make(SolutionUiBundleReader::class),
            $app->make(ConsoleExtensionRegistry::class),
            $app->make(Filesystem::class),
            $app->publicPath(),
        ));
        $this->app->singletonIf(PublishedSolutionUi::class);

        $this->app->bind(InstalledSolutionCatalogInterface::class, CoreInstalledSolutionCatalog::class);

        // Activation belongs to a tenant. `scoped`: a worker drops it before every job, and it keeps its
        // memo per tenant id, so no process holds one tenant's answer for another or past a deactivation.
        $this->app->bind(SolutionActivationStoreInterface::class, EloquentSolutionActivationStore::class);
        $this->app->scoped(ActiveSolutions::class);
        $this->app->bind(SolutionActivator::class);

        foreach ($this->app->make(InstalledSolutions::class)->all() as $solution) {
            $provider = $this->app->resolveProvider($solution->provider);
            $id       = $solution->id;

            $provider->booting(fn () => $this->app->make(ConsoleExtensionRegistry::class)->enter($id));
            $provider->booted(fn () => $this->app->make(ConsoleExtensionRegistry::class)->leave());

            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        $this->optimizes(optimize: 'solutions:cache', clear: 'solutions:clear');

        // Tests register fixture Solutions after boot, so phpunit.xml leaves the registry open (config/solutions.php).
        $this->app->booted(function (): void {
            if ((bool) config('solutions.freeze_console_registry', true)) {
                $this->app->make(ConsoleExtensionRegistry::class)->freeze();
            }
        });
    }
}
