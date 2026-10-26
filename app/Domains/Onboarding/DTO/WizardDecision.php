<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\DTO;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;

final readonly class WizardDecision
{
    /**
     * @param  OnboardingStepInterface|null  $step  the step to go to, for {@see WizardMove::NextStep}
     */
    public function __construct(
        public WizardMove $move,
        public ?OnboardingStepInterface $step,
    ) {
    }
}
