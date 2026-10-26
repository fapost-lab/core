<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Services;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
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

        $this->close();

        return new WizardDecision(WizardMove::Completed, null);
    }

    /**
     * Whether a step with this key is registered.
     */
    public function hasStep(string $key): bool
    {
        foreach ($this->progress->steps() as $step) {
            if ($step->key() === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next optional step that is still open, in the wizard's order: after the step with the key `$after`, or from
     * the start when it is null. Nothing is written: a step that was skipped is open again the next time it is asked.
     */
    public function nextOptional(?string $after = null): ?OnboardingStepInterface
    {
        $passed = null === $after;

        foreach ($this->progress->report()->steps as $progress) {
            if (! $passed) {
                $passed = $progress->step->key() === $after;

                continue;
            }

            if (! $progress->step->required() && ! $progress->complete) {
                return $progress->step;
            }
        }

        return null;
    }

    /**
     * The step to go back to from the step with the key `$currentKey`: the nearest earlier step of the same kind. A
     * required step goes back to a required one (its page can be reopened while the wizard is pending), an optional
     * step to an optional one; the first of its kind has nowhere to go back to.
     */
    public function previous(string $currentKey): ?OnboardingStepInterface
    {
        $steps = $this->progress->steps();

        foreach ($steps as $index => $step) {
            if ($step->key() !== $currentKey) {
                continue;
            }

            for ($before = $index - 1; $before >= 0; $before--) {
                if ($steps[$before]->required() === $step->required()) {
                    return $steps[$before];
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Closes the wizard, recording every registered step as known: the tenant has been through (or is populated
     * past) all of them, so only a step registered later counts as a late one. Also the entry for whatever creates
     * a tenant that is already populated.
     *
     * @param  bool  $populated  the tenant is populated rather than walked through the wizard (a seeder): it forgets
     *                           its start moment like a tenant that existed before the wizard, so it gets no checklist
     */
    public function close(bool $populated = false): void
    {
        $this->state->complete(array_map(
            static fn (OnboardingStepInterface $step): string => $step->key(),
            $this->progress->steps(),
        ), $populated);
    }

    /**
     * The steps in order, as the pages list them. `current` marks the step whose page is open (`$currentKey`, for an
     * optional step), else the first required step still open.
     *
     * @return list<array{key: string, required: bool, complete: bool, current: bool}>
     */
    public function stepProps(?string $currentKey = null): array
    {
        $report  = $this->progress->report();
        $current = $currentKey ?? $report->firstIncompleteRequired()?->step->key();
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
