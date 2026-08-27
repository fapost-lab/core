<?php

declare(strict_types=1);

namespace App\Providers;

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
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null) => in_array(optional($user)->email, [

        ]));
    }
}
