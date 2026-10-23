<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\DTO;

/**
 * Where the wizard goes next.
 */
enum WizardMove
{
    /** A required step is still open. */
    case NextStep;

    /** Everything required is done and the wizard has just closed. */
    case Completed;

    /** Everything required is done, but a check could not be made, so the wizard stays open. */
    case Held;
}
