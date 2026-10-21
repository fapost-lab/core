<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Exceptions\SolutionEntitlementUnavailableException;
use App\Domains\Solution\Exceptions\SolutionGrantedException;
use App\Domains\Solution\Exceptions\SolutionNotEntitledException;
use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Http\Shell\SolutionsPresenter;
use Fapost\Foundation\Solution\Contracts\SolutionStorefrontInterface;
use Fapost\Foundation\Solution\Enums\SolutionConnectOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The Solutions installed in this image and whether the tenant has each one on, in the admin panel: turn one on or off
 * for the tenant the staff user works in. A new screen (it has no Filament predecessor), so it has its own URI.
 *
 * Every change goes through {@see SolutionActivator} with the staff user as the actor, so it lands in the journal.
 * A Solution the operator does not entitle the tenant to, or whose console UI does not fit the kit this platform ships,
 * is refused with the reason (an entitlement that cannot be checked refuses too); turning one off is
 * always allowed, except for a Solution the operator grants (plan or add-on), which only the operator ends. Turning a
 * Solution off keeps its data. Connecting one the operator offers goes to its storefront, which starts a trial or sends
 * a request; Core writes nothing. The tenant is the one the request resolved; the request never names it.
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

        try {
            $change = $activator->deactivate($solution, $this->actor($request));
        } catch (SolutionGrantedException) {
            Inertia::flash('error', trans('console.solutions.flash.granted', ['solution' => $solution]));

            return $this->back();
        }

        Inertia::flash('success', trans(ActivationChange::Unchanged === $change ? 'console.solutions.flash.already_inactive' : 'console.solutions.flash.deactivated', ['solution' => $solution]));

        return $this->back();
    }

    /**
     * Asks the operator's storefront to connect a Solution it offers this tenant: a trial starts, or a request waits for
     * the operator. Refused while the operator's entitlement answer cannot be had (fail closed), and for a Solution it
     * does not offer.
     */
    public function connect(Request $request, string $solution, SolutionsPresenter $presenter, SolutionStorefrontInterface $storefront, ActiveSolutions $active, TenantContextInterface $tenantContext): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        abort_unless($presenter->isKnown($solution), 404);

        $user = $request->user();

        abort_unless($user instanceof User, 403);

        if (null === $active->entitlement()) {
            Inertia::flash('error', trans('console.solutions.flash.connect_unavailable'));

            return $this->back();
        }

        if (null === $presenter->offer($solution)) {
            Inertia::flash('error', trans('console.solutions.flash.not_offered', ['solution' => $solution]));

            return $this->back();
        }

        $tenantId = $tenantContext->get()->getId();

        try {
            $result = $storefront->connect($tenantId, $solution, $user->name);
        } catch (Throwable $exception) {
            report($exception);
            Inertia::flash('error', trans('console.solutions.flash.connect_unavailable'));

            return $this->back();
        }

        // A trial is a grant now: the next question in this request must ask the operator again.
        $active->forgetEntitlement($tenantId);

        $key = match ($result->outcome) {
            SolutionConnectOutcome::TrialStarted   => 'console.solutions.flash.trial_started',
            SolutionConnectOutcome::Requested      => 'console.solutions.flash.requested',
            SolutionConnectOutcome::AlreadyPending => 'console.solutions.flash.already_requested',
            default                                => null,
        };

        if (null === $key) {
            Inertia::flash('error', mb_trim(trans('console.solutions.flash.connect_refused', ['solution' => $solution]) . ' ' . ($result->message ?? '')));
        } else {
            Inertia::flash('success', trans($key, ['solution' => $solution]));
        }

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
