<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domains\Onboarding\DTO\WizardDecision;
use App\Domains\Onboarding\DTO\WizardMove;
use App\Domains\Onboarding\Services\OnboardingWizard;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Where the setup wizard's controllers send the person after a decision of {@see OnboardingWizard}.
 */
trait RedirectsWizard
{
    /**
     * The next open step; when the wizard has just closed, the first optional step that is still open, else the console
     * of the assistant (`console.entry` picks the first assistant the user may open, the one just created for an
     * administrator); the wizard's own address when the wizard is held, which shows the waiting page rather than send
     * the person round in a circle.
     */
    private function redirectFor(WizardDecision $decision): RedirectResponse
    {
        if (WizardMove::Completed === $decision->move) {
            $optional = app(OnboardingWizard::class)->nextOptional();

            if (null !== $optional) {
                Inertia::flash('success', trans('console.onboarding.required_done'));

                return redirect()->route($optional->route());
            }

            return $this->redirectToConsole();
        }

        if (WizardMove::NextStep === $decision->move && null !== $decision->step) {
            return redirect()->route($decision->step->route());
        }

        return redirect()->route('console.admin.onboarding.show');
    }

    /**
     * Onward from a step that is done or skipped: the next optional step that is still open after `$after`, else the
     * console. Writes nothing.
     *
     * @param  bool  $announce  whether reaching the console says the setup is done; a step that has just flashed its own
     *                          message leaves it out, since a second message would replace the first
     */
    private function redirectOnward(string $after, bool $announce = true): RedirectResponse
    {
        $next = app(OnboardingWizard::class)->nextOptional($after);

        if (null !== $next) {
            return redirect()->route($next->route());
        }

        return $this->redirectToConsole($announce);
    }

    private function redirectToConsole(bool $announce = true): RedirectResponse
    {
        if ($announce) {
            Inertia::flash('success', trans('console.onboarding.done'));
        }

        return redirect()->route('console.entry');
    }
}
