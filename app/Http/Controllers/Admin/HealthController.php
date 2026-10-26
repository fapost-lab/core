<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Health\Services\HealthService;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Http\Controllers\Controller;
use App\Http\Shell\HealthPresenter;
use App\Http\Shell\TenantHealthSlice;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The health of the installation's realtime services in the admin panel: Reverb and the webhook gateway, as the
 * health service reports them. A new screen (it has no Filament predecessor), so it has its own URI.
 *
 * In `single` mode the page shows the whole installation: Reverb, the gateway and the queues. In `host` mode it shows
 * the current tenant's slice only (its live channels and its channels' ingress, see {@see TenantHealthSlice}): the
 * rest is the whole installation's and a tenant's administrator must not read other tenants' channels from it. The
 * tenant is the one in the explicit tenant context, never one read from the request. The probes run in an optional
 * prop that only a partial reload asks for, so the page opens at once and shows a loading state while the browser
 * fetches `report` itself, and again every {@see self::POLL_SECONDS} seconds. It polls on purpose and does not use Reverb: a page about the websocket must
 * not depend on it.
 */
final class HealthController extends Controller
{
    public const int POLL_SECONDS = 15;

    public function index(HealthService $health, HealthPresenter $presenter, TenantHealthSlice $slice, TenantContextInterface $tenants): Response
    {
        Gate::authorize(Permission::ViewSystem->value);

        $single = TenancyResolutionMode::Single === TenancyResolutionMode::fromConfig();

        return Inertia::render('Console/AdminHealth/Index', [
            'pollSeconds' => self::POLL_SECONDS,
            'scope'       => $single ? 'installation' : 'tenant',
            'report'      => Inertia::optional(fn (): array => $single
                ? $presenter->page($health->report())
                : $presenter->tenantPage($slice->of($health->report(), $tenants->get()->getId()))),
        ]);
    }
}
