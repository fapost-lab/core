<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Exceptions\SolutionEntitlementUnavailableException;
use App\Domains\Solution\Exceptions\SolutionNotEntitledException;
use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Shell\SolutionsPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Solutions installed in this image and whether the tenant has each one on, in the admin panel: turn one on or off
 * for the tenant the staff user works in. A new screen (it has no Filament predecessor), so it has its own URI.
 *
 * Every change goes through {@see SolutionActivator} with the staff user as the actor, so it lands in the journal.
 * A Solution the operator does not entitle the tenant to, or whose console UI does not fit the kit this platform ships,
 * is refused with the reason (an entitlement that cannot be checked refuses too); turning one off is
 * always allowed. Turning a Solution off keeps its data. The tenant is the one the request resolved; the request never names it.
 */
final class SolutionController extends Controller
{
    public function index(SolutionsPresenter $presenter): Response
    {
        Gate::authorize(Permission::ManageSettings->value);

        return Inertia::render('Console/AdminSolutions/Index', $presenter->page());
    }

    public function activate(Request $request, string $solution, SolutionActivator $activator, SolutionsPresenter $presenter): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        abort_unless($presenter->isKnown($solution), 404);

        $refusal = $presenter->refusal($solution);

        if (null !== $refusal) {
            Inertia::flash('error', trans('console.solutions.flash.refused', ['solution' => $solution, 'reasons' => $refusal]));

            return $this->back();
        }

        if (! $presenter->isActivatable($solution)) {
            Inertia::flash('error', trans('console.solutions.flash.not_allowed'));

            return $this->back();
        }

        try {
            $change = $activator->activate($solution, $this->actor($request));
        } catch (SolutionNotInstalledException) {
            abort(404);
        } catch (SolutionNotEntitledException|SolutionEntitlementUnavailableException $exception) {
            // The entitlement changed between the check above and the write: refuse with the same reason.
            Inertia::flash('error', trans('console.solutions.flash.refused', ['solution' => $solution, 'reasons' => trans($exception instanceof SolutionNotEntitledException ? 'console.solutions.entitlement.not_entitled' : 'console.solutions.entitlement.unavailable')]));

            return $this->back();
        }

        Inertia::flash('success', trans(ActivationChange::Unchanged === $change ? 'console.solutions.flash.already_active' : 'console.solutions.flash.activated', ['solution' => $solution]));

        return $this->back();
    }

    public function deactivate(Request $request, string $solution, SolutionActivator $activator, SolutionsPresenter $presenter): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        abort_unless($presenter->isKnown($solution), 404);

        $change = $activator->deactivate($solution, $this->actor($request));

        Inertia::flash('success', trans(ActivationChange::Unchanged === $change ? 'console.solutions.flash.already_inactive' : 'console.solutions.flash.deactivated', ['solution' => $solution]));

        return $this->back();
    }

    private function actor(Request $request): ActivationActor
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return ActivationActor::user((string) $user->getKey(), $user->name);
    }

    private function back(): RedirectResponse
    {
        return redirect()->route('console.admin.solutions.index', [], 303);
    }
}
