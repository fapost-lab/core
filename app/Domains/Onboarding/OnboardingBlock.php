<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\OnboardingOrigin;

/**
 * The steps one owner adds to the wizard, in their order.
 *
 * Steps are named by class, not held as objects: the registry lives as long as the worker, while a step reads the
 * tenant's data and so is built in the scope that asks.
 */
final readonly class OnboardingBlock
{
    /**
     * @param  list<class-string<OnboardingStepInterface>>  $steps
     */
    public function __construct(
        public OnboardingOrigin $origin,
        public array $steps,
    ) {
    }
}
