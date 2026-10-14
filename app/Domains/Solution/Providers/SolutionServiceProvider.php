<?php

declare(strict_types=1);

namespace App\Domains\Solution\Providers;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\Infrastructure\CoreInstalledSolutionCatalog;
use App\Domains\Solution\Infrastructure\EloquentSolutionActivationStore;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Solution\Services\SolutionCache;
use Fapost\Foundation\Solution\Contracts\InstalledSolutionCatalogInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Solution domain and boots the service provider of every installed Solution with a valid
 * manifest. A Solution's provider is never listed in `extra.laravel.providers`: Laravel's package
 * discovery would boot it whether or not its manifest holds up.
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

        $this->app->bind(InstalledSolutionCatalogInterface::class, CoreInstalledSolutionCatalog::class);

        // Activation belongs to a tenant. `scoped`: a worker drops it before every job, and it keeps its
        // memo per tenant id, so no process holds one tenant's answer for another or past a deactivation.
        $this->app->bind(SolutionActivationStoreInterface::class, EloquentSolutionActivationStore::class);
        $this->app->scoped(ActiveSolutions::class);
        $this->app->bind(SolutionActivator::class);

        foreach ($this->app->make(InstalledSolutions::class)->all() as $solution) {
            $this->app->register($solution->provider);
        }
    }

    public function boot(): void
    {
        $this->optimizes(optimize: 'solutions:cache', clear: 'solutions:clear');
    }
}
