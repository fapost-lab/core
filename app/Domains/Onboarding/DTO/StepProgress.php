<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\DTO;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;

/**
 * One step and what the last check found. An uncertain step could not be checked: it is counted complete so that a
 * failing check does not lock the tenant out, but the wizard does not close on it.
 */
final readonly class StepProgress
{
    public function __construct(
        public OnboardingStepInterface $step,
        public bool $complete,
        public bool $uncertain,
    ) {
    }
}
