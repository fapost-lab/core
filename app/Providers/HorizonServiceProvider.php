<?php

declare(strict_types=1);

namespace App\Providers;

use Fapost\Foundation\Operator\Contracts\OperatorAccessInterface;
use Fapost\Foundation\Operator\Enums\OperatorSurface;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Laravel Horizon configuration provider.
 *
 * Allows customizing Horizon boot behavior and applies a gate for non-local access.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Boot Horizon and apply optional runtime configuration.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * Horizon asks the operator access contract about its own surface and ignores the gate's user: the
     * contract reads the request's sign-in itself. Core's answer opens Horizon on a `single` installation to
     * staff holding the operator-screens permission and keeps `host` mode closed; in `host` mode Horizon is also
     * served on platform hosts only (see config/horizon.php), where an operator layer binds its own answer.
     * Without one, run `horizon:status` and read the logs.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn (mixed $user = null): bool => app(OperatorAccessInterface::class)
            ->canView(request(), OperatorSurface::Horizon));
    }
}
