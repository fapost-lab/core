<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Platform\ArtisanPlatformMigrator;
use App\Console\Platform\PlatformMigratorInterface;
use App\Console\Platform\TenantUnservedChannelsFinder;
use App\Console\Platform\UnservedChannelsFinderInterface;
use App\Domains\Flow\Logging\DatabaseAnalyticsWriter;
use App\Domains\Shared\Infrastructure\ModelAttributeRegistry;
use App\Http\Shell\SolutionExtensions;
use App\Http\Shell\SolutionImportMap;
use App\Http\Shell\SolutionUi;
use App\Http\Shell\SolutionUiMapInterface;
use Fapost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use Fapost\Foundation\Contracts\ModelAttributeResolverInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
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
        $this->app->bind(PlatformMigratorInterface::class, ArtisanPlatformMigrator::class);
        $this->app->bind(UnservedChannelsFinderInterface::class, TenantUnservedChannelsFinder::class);

        Auth::provider('legacy-uuid-eloquent', function ($app, array $config): LegacyUuidUserProvider {
            /** @var class-string<\Illuminate\Contracts\Auth\Authenticatable&\Illuminate\Database\Eloquent\Model> $model */
            $model = $config['model'];

            return new LegacyUuidUserProvider($app['hash'], $model);
        });

        $this->app->singleton(ModelAttributeRegistry::class);
        $this->app->singleton(ModelAttributeResolverInterface::class, ModelAttributeRegistry::class);
        $this->app->scoped(DatabaseAnalyticsWriter::class);
        $this->app->scoped(AnalyticsWriterInterface::class, DatabaseAnalyticsWriter::class);
        // Per request: what a Solution adds depends on the tenant's active Solutions and on the user.
        $this->app->scoped(SolutionUi::class);
        $this->app->scoped(SolutionUiMapInterface::class, static fn ($app): SolutionUi => $app->make(SolutionUi::class));
        $this->app->scoped(SolutionExtensions::class);

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

        // The console root view's import map and Solution bundle map; it must precede `@vite`.
        Blade::directive('solutionImports', static fn (): string => '<?php echo app(\\' . SolutionImportMap::class . '::class)->render(); ?>');

        $this->app->booted(function (): void {
            $this->app->make(ModelAttributeRegistry::class)->freeze();
        });
    }
}
