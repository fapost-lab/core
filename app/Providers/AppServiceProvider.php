<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Shared\Infrastructure\ModelAttributeRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Application-level service provider.
 *
 * Binds shared infrastructure services and applies basic boot-time safeguards.
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register application singletons and environment-specific providers.
     *
     * Current responsibility: bind {@see ModelAttributeRegistry} as a container singleton.
     */
    public function register(): void
    {
        $this->app->singleton(ModelAttributeRegistry::class);

        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Apply safeguards and load base platform migrations.
     */
    public function boot(): void
    {
        DB::prohibitDestructiveCommands(
            $this->app->environment('production')
        );

        $this->loadMigrationsFrom(database_path('migrations/landlord'));
    }
}
