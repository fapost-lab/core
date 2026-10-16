<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Tenancy\Services\TenantUsageOverview;
use App\Http\Controllers\Controller;
use App\Http\Shell\UsagePresenter;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How much of each limit the tenant uses, in the admin panel: Core's own counts for records and stored bytes, and the
 * operator's report for per-period use. A new screen (it has no Filament predecessor), so it has its own URI.
 *
 * Read only and without polling: the figures are those of the moment the page is opened. The operator is asked once
 * per page view, and a failing operator only turns its rows into "unavailable".
 */
final class UsageController extends Controller
{
    public function index(TenantUsageOverview $overview, UsagePresenter $presenter): Response
    {
        Gate::authorize(Permission::ManageSettings->value);

        return Inertia::render('Console/AdminUsage/Index', $presenter->page($overview->overview(app()->getLocale())));
    }
}
