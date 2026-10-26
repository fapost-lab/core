<?php

declare(strict_types=1);

namespace App\Domains\Health\Providers;

use App\Domains\Health\Probes\GatewayProbe;
use App\Domains\Health\Probes\QueueProbe;
use App\Domains\Health\Probes\ReverbProbe;
use App\Domains\Health\Probes\WebhookRegistryProbe;
use App\Domains\Health\Services\CoreInstallationHealth;
use App\Domains\Health\Services\HealthService;
use Fapost\Foundation\Health\Contracts\InstallationHealthInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\ServiceProvider;

final class HealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: the probes reach the config repository and the tenant switcher, which a worker must
        // not keep across jobs (conventions/worker-safety.md).
        $this->app->scoped(
            HealthService::class,
            fn ($app): HealthService => new HealthService(
                probes: [
                    $app->make(ReverbProbe::class),
                    $app->make(GatewayProbe::class),
                    $app->make(WebhookRegistryProbe::class),
                    $app->make(QueueProbe::class),
                ],
                cache: $app->make(Repository::class),
            ),
        );

        // The installation's health for a package that renders it. bindIf: an operator layer that bound its own answer
        // keeps it. Not shared: it holds nothing and resolves the scoped service each time.
        $this->app->bindIf(InstallationHealthInterface::class, CoreInstallationHealth::class);
    }
}
