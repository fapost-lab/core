<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use App\Domains\Tenancy\Database\TenantDatabaseManager;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\ConfigTenantResolver;
use App\Domains\Tenancy\Services\CoreBootstrap;
use App\Domains\Tenancy\Services\DomainBootstrapper;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Services\WebhookRegistryWriter;
use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContextInterface::class, TenantContext::class);

        $this->app->singleton(
            TenantDatabaseManagerInterface::class,
            TenantDatabaseManager::class,
        );

        $this->app->bind(
            TenantRepositoryInterface::class,
            TenantRepository::class,
        );

        $this->app->bind(TenantResolverInterface::class, ConfigTenantResolver::class);

        $this->app->singleton(CoreBootstrap::class);
        $this->app->singleton(CoreBootstrapInterface::class, fn ($app): CoreBootstrap => $app->make(CoreBootstrap::class));
        $this->app->singleton(DomainBootstrapper::class);
        $this->app->singleton(TenantSwitcher::class);
        $this->app->singleton(WebhookRegistryWriter::class);
    }
}
