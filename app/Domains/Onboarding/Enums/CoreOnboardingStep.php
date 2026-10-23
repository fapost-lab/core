<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Enums;

/**
 * The steps of Core's block of the wizard. The case order is the order of the steps.
 */
enum CoreOnboardingStep: string
{
    case Languages      = 'languages';
    case FirstAssistant = 'first_assistant';
}
