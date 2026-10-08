<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Staff\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

/**
 * Laravel Telescope configuration provider.
 *
 * Applies request/job filtering and hides sensitive request parameters everywhere and sensitive headers in non-local environments.
 */
final class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register Telescope configuration.
     *
     * Enables filtering for non-local environments and calls internal safeguards for sensitive data.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(fn (IncomingEntry $entry) => $isLocal
                                                       || $entry->isReportableException()
                                                       || $entry->isFailedRequest()
                                                       || $entry->isFailedJob()
                                                       || $entry->isScheduledTask()
                                                       || $entry->hasMonitoredTag());
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        // In every environment, local included: `token` is the single-use secret of the support
        // access entry (`POST /support/enter`, also ignored by path in config/telescope.php).
        Telescope::hideRequestParameters(['_token', 'token']);

        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', static fn (User $user) => in_array($user->email, [

        ], true));
    }
}
