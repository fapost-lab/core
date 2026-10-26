<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\CoreOnboardingStep;
use App\Domains\Onboarding\Services\OnboardingState;

/**
 * The content languages. Nothing in the data says the tenant looked at them (the defaults are valid), so the step is
 * complete only when a person confirmed it.
 */
final readonly class LanguagesStep implements OnboardingStepInterface
{
    public function __construct(private OnboardingState $state)
    {
    }

    public function key(): string
    {
        return CoreOnboardingStep::Languages->value;
    }

    public function required(): bool
    {
        return true;
    }

    public function route(): string
    {
        return 'console.admin.onboarding.languages.edit';
    }

    public function routes(): array
    {
        return [
            'console.admin.onboarding.languages.edit',
            'console.admin.onboarding.languages.update',
        ];
    }

    public function isComplete(): bool
    {
        return $this->state->isConfirmed($this->key());
    }
}
