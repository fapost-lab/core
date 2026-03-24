<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\TenantContext;
use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContextInterface::class, TenantContext::class);
    }
}
