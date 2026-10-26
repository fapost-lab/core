<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Onboarding\DTO\WizardMove;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Services\OnboardingWizard;
use App\Domains\Staff\Enums\Permission;
use App\Http\Controllers\Concerns\RedirectsWizard;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The entry of the first-run setup wizard (`admin/onboarding`): sends the person to where the wizard stands.
 *
 * - The wizard is not pending: the dashboard.
 * - The user may not manage settings: the waiting page (`Console/Onboarding/Waiting`), never a 403, since the gate
 *   sends every user of a pending tenant here.
 * - Otherwise the first open required step; when none is open the wizard closes and the person lands in the
 *   assistant's console. When a check could not be made the wizard stays pending and the administrator goes to the dashboard,
 *   which the gate lets through in that case (no loop).
 *
 * `continue/{step}` is "go on after this step": the next optional step that is still open, else the console. It writes
 * nothing, so a step that was skipped stays open in the checklist and is offered again by a restart. While the wizard
 * is pending it sends the person to the entry instead, since a required step comes first.
 *
 * Props of the page rendered here (`Console/Onboarding/Waiting`):
 *   onboarding: { steps: list<{key: string, required: bool, complete: bool, current: bool}> }
 *
 * The gate ({@see \App\Http\Middleware\EnsureTenantOnboarded}) only reads the state; this is one of the two places
 * that decide it is over, the other being the step controllers through {@see OnboardingWizard::advance()}.
 */
final class OnboardingController extends Controller
{
    use RedirectsWizard;

    public function __construct(
        private readonly OnboardingState $state,
        private readonly OnboardingWizard $wizard,
    ) {
    }

    public function show(Request $request): Response|RedirectResponse
    {
        if (! $this->state->isPending()) {
            return redirect()->route('console.admin.dashboard');
        }

        if (true !== $request->user()?->can(Permission::ManageSettings->value)) {
            return $this->waiting();
        }

        $decision = $this->wizard->advance();

        if (WizardMove::Held === $decision->move) {
            return redirect()->route('console.admin.dashboard');
        }

        return $this->redirectFor($decision);
    }

    public function continue(string $step): RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        abort_unless($this->wizard->hasStep($step), 404);

        if ($this->state->isPending()) {
            return redirect()->route('console.admin.onboarding.show');
        }

        return $this->redirectOnward($step);
    }

    private function waiting(): Response
    {
        return Inertia::render('Console/Onboarding/Waiting', [
            'onboarding' => ['steps' => $this->wizard->stepProps()],
        ]);
    }
}
