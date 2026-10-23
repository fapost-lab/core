<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Enums;

/**
 * Where a tenant stands in the first-run wizard. Stored as the value.
 */
enum OnboardingStatus: string
{
    case Pending  = 'pending';
    case Complete = 'complete';
}
