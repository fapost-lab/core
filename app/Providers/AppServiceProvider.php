<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Flow\Logging\DatabaseAnalyticsWriter;
use App\Domains\Shared\Infrastructure\ModelAttributeRegistry;
use FAPost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use FAPost\Foundation\Contracts\ModelAttributeResolverInterface;
use Illuminate\Support\Facades\Auth;
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
        Auth::provider('legacy-uuid-eloquent', function ($app, array $config): LegacyUuidUserProvider {
            /** @var class-string<\Illuminate\Contracts\Auth\Authenticatable&\Illuminate\Database\Eloquent\Model> $model */
            $model = $config['model'];

            return new LegacyUuidUserProvider($app['hash'], $model);
        });

        $this->app->singleton(ModelAttributeRegistry::class);
        $this->app->singleton(ModelAttributeResolverInterface::class, ModelAttributeRegistry::class);
        $this->app->scoped(DatabaseAnalyticsWriter::class);
        $this->app->scoped(AnalyticsWriterInterface::class, DatabaseAnalyticsWriter::class);

        // Telescope is a dev dependency, so a production install does not have it.
        // The class check matters beyond that: an image built with --no-dev but
        // started with APP_ENV=local would otherwise fail to boot at all, with an
        // error naming a package the operator never asked for.
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
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

        $this->app->booted(function (): void {
            $this->app->make(ModelAttributeRegistry::class)->freeze();
        });
    }
}
