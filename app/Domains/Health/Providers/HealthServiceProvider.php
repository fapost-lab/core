<?php

declare(strict_types=1);

namespace App\Domains\Health\Providers;

use App\Domains\Health\Probes\GatewayProbe;
use App\Domains\Health\Probes\ReverbProbe;
use App\Domains\Health\Probes\WebhookRegistryProbe;
use App\Domains\Health\Services\HealthService;
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
                ],
                cache: $app->make(Repository::class),
            ),
        );
    }
}
