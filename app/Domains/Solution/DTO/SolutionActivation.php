<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

use App\Domains\Solution\Enums\SolutionActivationStatus;
use DateTimeInterface;

/**
 * One tenant's stored choice for one Solution. Says nothing about whether the Solution is installed.
 */
final readonly class SolutionActivation
{
    public function __construct(
        public string $solutionId,
        public SolutionActivationStatus $status,
        public DateTimeInterface $activatedAt,
        public ?DateTimeInterface $deactivatedAt,
    ) {
    }
}
