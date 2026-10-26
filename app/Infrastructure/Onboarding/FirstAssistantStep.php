<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\CoreOnboardingStep;

/**
 * The first assistant. Derived from the data: a tenant that has an assistant has done it, however it got there.
 */
final readonly class FirstAssistantStep implements OnboardingStepInterface
{
    public function key(): string
    {
        return CoreOnboardingStep::FirstAssistant->value;
    }

    public function required(): bool
    {
        return true;
    }

    public function route(): string
    {
        return 'console.admin.onboarding.assistant.create';
    }

    public function routes(): array
    {
        return [
            'console.admin.onboarding.assistant.create',
            'console.admin.onboarding.assistant.store',
        ];
    }

    public function isComplete(): bool
    {
        return Assistant::query()->exists();
    }
}
