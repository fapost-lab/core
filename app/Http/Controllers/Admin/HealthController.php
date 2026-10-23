<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Health\Services\HealthService;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Http\Controllers\Controller;
use App\Http\Shell\HealthPresenter;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The health of the installation's realtime services in the admin panel: Reverb and the webhook gateway, as the
 * health service reports them. A new screen (it has no Filament predecessor), so it has its own URI.
 *
 * Only in `single` mode (404 otherwise): the figures are the whole installation's, and in `host` mode a tenant's
 * administrator must not see other tenants' channels. Their slice is a separate screen. The probes run in an optional
 * prop that only a partial reload asks for, so the page opens at once and shows a loading state while the browser
 * fetches `report` itself, and again every {@see self::POLL_SECONDS} seconds. It polls on purpose and does not use Reverb: a page about the websocket must
 * not depend on it.
 */
final class HealthController extends Controller
{
    public const int POLL_SECONDS = 15;

    public function index(HealthService $health, HealthPresenter $presenter): Response
    {
        abort_unless(TenancyResolutionMode::Single === TenancyResolutionMode::fromConfig(), 404);
        Gate::authorize(Permission::ViewSystem->value);

        return Inertia::render('Console/AdminHealth/Index', [
            'pollSeconds' => self::POLL_SECONDS,
            'report'      => Inertia::optional(fn (): array => $presenter->page($health->report())),
        ]);
    }
}
