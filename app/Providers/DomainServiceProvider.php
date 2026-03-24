<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantDatabaseManager;
use App\Domains\Tenancy\Services\TenantRepository;
use App\Domains\Tenancy\Services\WebhookRegistryWriter;
use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContextInterface::class, TenantContext::class);

        $this->app->scoped(
            TenantDatabaseManagerInterface::class,
            TenantDatabaseManager::class,
        );

        $this->app->singleton(
            TenantRepositoryInterface::class,
            TenantRepository::class,
        );

        $this->app->singleton(WebhookRegistryWriter::class);
    }
}
