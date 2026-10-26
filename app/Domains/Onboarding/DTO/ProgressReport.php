<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\DTO;

/**
 * The steps of the wizard in order, as checked at one moment.
 */
final readonly class ProgressReport
{
    /**
     * @param  list<StepProgress>  $steps
     */
    public function __construct(public array $steps)
    {
    }

    public function firstIncompleteRequired(): ?StepProgress
    {
        foreach ($this->steps as $progress) {
            if ($progress->step->required() && ! $progress->complete) {
                return $progress;
            }
        }

        return null;
    }

    public function hasUncertain(): bool
    {
        foreach ($this->steps as $progress) {
            if ($progress->uncertain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every required step is done and every check could be made: the only state in which the wizard may close.
     */
    public function canComplete(): bool
    {
        return null === $this->firstIncompleteRequired() && ! $this->hasUncertain();
    }
}
