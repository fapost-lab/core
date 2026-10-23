<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domains\Onboarding\DTO\WizardDecision;
use App\Domains\Onboarding\DTO\WizardMove;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Where the setup wizard's controllers send the person after a decision of {@see \App\Domains\Onboarding\Services\OnboardingWizard}.
 */
trait RedirectsWizard
{
    /**
     * The next open step; the console of the assistant when the wizard has just closed (`console.entry` picks the first
     * assistant the user may open, the one just created for an administrator); the wizard's own address when the
     * wizard is held, which shows the waiting page rather than send the person round in a circle.
     */
    private function redirectFor(WizardDecision $decision): RedirectResponse
    {
        if (WizardMove::Completed === $decision->move) {
            Inertia::flash('success', trans('console.onboarding.done'));

            return redirect()->route('console.entry');
        }

        if (WizardMove::NextStep === $decision->move && null !== $decision->step) {
            return redirect()->route($decision->step->route());
        }

        return redirect()->route('console.admin.onboarding.show');
    }
}
