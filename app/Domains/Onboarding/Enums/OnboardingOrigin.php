<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Enums;

/**
 * Who owns a block of steps. The case order is the order of the blocks in the wizard.
 */
enum OnboardingOrigin: string
{
    case Shell   = 'shell';
    case Core    = 'core';
    case Package = 'package';
}
