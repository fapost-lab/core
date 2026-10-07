<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Controllers;

use App\Domains\Staff\Services\SupportAccessEntryService;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Where a platform operator enters a tenant, and leaves it, as the tenant's platform support user.
 *
 * `enter` is exempt from CSRF: the operator's browser arrives from another site with a form post,
 * and the single-use token in the body is the secret that proves the request.
 */
final class SupportAccessController extends Controller
{
    public function __construct(
        private readonly SupportAccessEntryService $entries,
    ) {
    }

    public function enter(Request $request): RedirectResponse
    {
        // Off means the route does not exist; nothing is consumed.
        abort_unless(true === config('tenancy.support_access.enabled'), 404);

        // Body only: a token in the query string is a leaked one and is not even read.
        $token = $request->post('token');

        abort_unless(is_string($token) && '' !== $token, 403, __('staff.support_access.invalid_link'));

        $entered = $this->entries->enter($token, $request->ip());

        abort_if(null === $entered, 403, __('staff.support_access.invalid_link'));

        $guard = Auth::guard();

        if ($guard->check()) {
            // Whoever is signed in here goes: a previous support session closes its entry on logout.
            $guard->logout();
        }

        $request->session()->invalidate();

        // No "remember me": the session is the only way in, and it ends on its own.
        $guard->login($entered['user'], false);
        $request->session()->regenerate();

        new SupportAccessSession($request->session())->start($entered['entry']);

        return redirect()->to(route('filament.admin.pages.dashboard'));
    }

    public function leave(Request $request): RedirectResponse
    {
        abort_unless(null !== new SupportAccessSession($request->session())->current(), 404);

        // The logout listener closes the entry.
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(route('filament.admin.auth.login'));
    }
}
