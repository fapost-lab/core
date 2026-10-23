<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Services;

use App\Domains\Onboarding\DTO\WizardDecision;
use App\Domains\Onboarding\DTO\WizardMove;

/**
 * What the wizard does after anything changed: sends the person to the next open required step, or closes.
 *
 * The one place that writes `complete` for a wizard that was walked through (the gate never decides anything).
 */
final readonly class OnboardingWizard
{
    public function __construct(
        private OnboardingProgress $progress,
        private OnboardingState $state,
    ) {
    }

    /**
     * Rechecks the steps and moves on. Closes the wizard when every required step is done and no check was uncertain.
     */
    public function advance(): WizardDecision
    {
        $report = $this->progress->report();
        $next   = $report->firstIncompleteRequired();

        if (null !== $next) {
            return new WizardDecision(WizardMove::NextStep, $next->step);
        }

        if ($report->hasUncertain()) {
            return new WizardDecision(WizardMove::Held, null);
        }

        $this->state->complete();

        return new WizardDecision(WizardMove::Completed, null);
    }

    /**
     * The steps in order, as the pages list them. `current` marks the first required step still open.
     *
     * @return list<array{key: string, required: bool, complete: bool, current: bool}>
     */
    public function stepProps(): array
    {
        $report  = $this->progress->report();
        $current = $report->firstIncompleteRequired()?->step->key();
        $props   = [];

        foreach ($report->steps as $progress) {
            $props[] = [
                'key'      => $progress->step->key(),
                'required' => $progress->step->required(),
                'complete' => $progress->complete,
                'current'  => $progress->step->key() === $current,
            ];
        }

        return $props;
    }
}
