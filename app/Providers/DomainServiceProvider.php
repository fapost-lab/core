<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Database\TenantDatabaseManager;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\ConfigTenantResolver;
use App\Domains\Tenancy\Services\CoreBootstrap;
use App\Domains\Tenancy\Services\DomainBootstrapper;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Services\WebhookRegistryWriter;
use Illuminate\Support\ServiceProvider;

/**
 * Tenancy domain provider.
 *
 * Registers tenant-context, tenant database management, runtime bootstrapping, and webhook registry writing.
 */
final class DomainServiceProvider extends ServiceProvider
{
    /**
     * Register tenancy bindings (scoped/singleton) for per-request tenant isolation.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContextInterface::class, TenantContext::class);

        $this->app->scoped(
            TenantDatabaseManagerInterface::class,
            TenantDatabaseManager::class,
        );

        $this->app->bind(
            TenantRepositoryInterface::class,
            TenantRepository::class,
        );

        $this->app->bind(TenantResolverInterface::class, ConfigTenantResolver::class);

        $this->app->scoped(CoreBootstrap::class);
        $this->app->scoped(CoreBootstrapInterface::class, fn ($app): CoreBootstrap => $app->make(CoreBootstrap::class));
        $this->app->scoped(DomainBootstrapper::class);
        $this->app->scoped(TenantSwitcher::class);
        $this->app->singleton(WebhookRegistryWriterInterface::class, WebhookRegistryWriter::class);
    }
}
